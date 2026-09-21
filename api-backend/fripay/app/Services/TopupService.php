<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Topup;
use App\Models\User;
use App\Services\Connectors\FeexpayConnector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Recharge du wallet FriPay via l'agrégateur FeexPay (collecte mobile money
 * MTN/Moov) — en attendant les API natives MTN/Moov/Celtiis.
 *
 * Flux (cahier des charges — « Dépôt » renommé « Recharge ») :
 *   1. POST /wallet/topup/initiate  -> crée un Topup local (statut pending)
 *      et une demande de paiement FeexPay (push USSD/STK sur le téléphone).
 *   2. L'utilisateur valide sur son téléphone.
 *   3. POST /webhooks/feexpay      -> (ou GET /wallet/topup/{ref}/status)
 *      -> statut SUCCESSFUL => crédit idempotent du wallet + notification.
 *
 * Idempotence du crédit : garantie au niveau DB (index unique sur
 * wallet_ledger_entries.transaction_id) — un webhook rejoué ne peut pas
 * créditer deux fois.
 */
class TopupService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly FeexpayConnector $feexpay,
    ) {}

    /**
     * Crée le topup local (statut `pending`). L'appel FeexPay n'est PAS
     * fait ici : il est effectué par le job InitiateTopupPayment (queue
     * `feexpay`) afin de ne jamais bloquer un worker HTTP 30 s sur
     * l'agrégateur. Le wallet n'est crédité qu'à la CONFIRMATION
     * (webhook ou vérification active).
     */
    public function initiate(User $user, float $amount, string $operator): Topup
    {
        return Topup::create([
            'id'              => (string) Str::uuid(),
            'user_id'         => $user->id,
            'amount'          => $amount,
            'currency'        => 'XOF',
            'operator_code'   => strtoupper($operator),
            'phone_number'    => $user->phone_number, // E.164 +22901XXXXXXXX
            'status'          => 'pending',
            'reference'       => 'TOP-' . strtoupper(Str::random(12)),
        ]);
    }

    /**
     * Soumet la collecte FeexPay d'un topup déjà créé. Exécuté par le job
     * InitiateTopupPayment (worker Horizon), plus par le cycle HTTP.
     *
     * Retourne le résultat brut du connecteur :
     *   success / retryable / reference / payment_url / message.
     */
    public function submitToProvider(Topup $topup): array
    {
        $user = User::find($topup->user_id);

        $result = $this->feexpay->requestToPay([
            'amount'      => (int) $topup->amount,
            'phone'       => ltrim((string) $topup->phone_number, '+'),
            'operator'    => $topup->operator_code,
            'first_name'  => $user?->first_name,
            'last_name'   => $user?->last_name,
            'email'       => $user?->email,
            'reference'   => $topup->reference,
            'description' => 'Recharge wallet FriPay ' . $topup->reference,
        ]);

        if ($result['success']) {
            $topup->update([
                'provider_reference' => $result['reference'],
                'status'             => 'processing',
            ]);
        } elseif (! ($result['retryable'] ?? false)) {
            // Rejet définitif : le job ne relancera pas — tracer tout de
            // suite. (L'indisponibilité temporaire reste au job de la gérer.)
            $topup->update([
                'status'         => 'failed',
                'failure_reason' => $result['message'],
            ]);

            Log::warning('Recharge FeexPay : collecte rejetée', [
                'topup'  => $topup->id,
                'reason' => $result['message'],
            ]);
        }

        return $result;
    }

    /**
     * Vérifie le statut d'un topup auprès de FeexPay et crédite le wallet
     * si le paiement est confirmé. Sûr à appeler plusieurs fois (idempotent).
     */
    public function refreshStatus(Topup $topup): Topup
    {
        if (! in_array($topup->status, ['pending', 'processing'], true)) {
            return $topup; // déjà réglé (completed/failed) : rien à faire
        }

        if (! $topup->provider_reference) {
            return $topup; // jamais soumis à FeexPay
        }

        $status = $this->feexpay->getStatus($topup->provider_reference);

        if (($status['status'] ?? null) === FeexpayConnector::STATUS_SUCCESSFUL) {
            $this->markCompleted($topup, 'Confirmé via FeexPay');
        } elseif (($status['status'] ?? null) === FeexpayConnector::STATUS_FAILED) {
            $this->markFailed($topup, 'Paiement refusé (FeexPay)');
        }
        // PENDING / inconnu : on ne change rien.

        return $topup->fresh();
    }

    /**
     * Marque un topup comme payé et crédite le wallet (idempotent).
     * Appelé par le webhook FeexPay ou par refreshStatus().
     */
    public function markCompletedByProviderReference(string $providerReference, string $note = 'Confirmé via FeexPay'): ?Topup
    {
        $topup = Topup::where('provider_reference', $providerReference)->first();

        if (! $topup) {
            return null;
        }

        $this->markCompleted($topup, $note);

        return $topup->fresh();
    }

    /**
     * Recherche le topup par référence FeexPay puis vérifie son statut en
     * temps réel auprès de l'API (source de vérité) et crédite si confirmé.
     * Utilisé par le webhook — sûr à appeler plusieurs fois.
     */
    public function refreshStatusByProviderReference(string $providerReference): ?Topup
    {
        $topup = Topup::where('provider_reference', $providerReference)->first();

        if (! $topup) {
            Log::warning('Webhook FeexPay : topup introuvable', ['reference' => $providerReference]);

            return null;
        }

        return $this->refreshStatus($topup);
    }

    private function markCompleted(Topup $topup, string $note): void
    {
        if ($topup->status === 'completed') {
            return;
        }

        $topup->update(['status' => 'completed', 'completed_at' => now()]);

        // Crédit idempotent : l'index unique wallet_ledger_entries.transaction_id
        // empêche tout double crédit même si le webhook est rejoué.
        $this->wallets->credit(
            $topup->user_id,
            (float) $topup->amount,
            $topup->id,
            'feexpay_topup',
            'Recharge ' . $topup->reference . ' via ' . $topup->operator_code . ' (' . $note . ')'
        );

        $this->notify(
            $topup->user_id,
            'Recharge réussie',
            'Votre recharge de ' . number_format((float) $topup->amount, 0, ',', ' ')
            . ' FCFA a été créditée sur votre compte FriPay.',
            $topup->id
        );
    }

    private function markFailed(Topup $topup, string $reason): void
    {
        if (in_array($topup->status, ['completed', 'failed'], true)) {
            return;
        }

        $topup->update(['status' => 'failed', 'failure_reason' => $reason]);

        $this->notify(
            $topup->user_id,
            'Recharge échouée',
            'Votre recharge de ' . number_format((float) $topup->amount, 0, ',', ' ')
            . ' FCFA n\'a pas abouti : ' . $reason,
            $topup->id
        );
    }

    private function notify(string $userId, string $title, string $body, string $topupId): void
    {
        Notification::create([
            'user_id'                => $userId,
            'type'                   => 'transaction_update',
            'channel'                => 'in_app',
            'title'                  => $title,
            'body'                   => $body,
            'related_transaction_id' => $topupId,
            'read'                   => false,
        ]);
    }
}
