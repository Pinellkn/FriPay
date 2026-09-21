<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PaymentLink;
use App\Models\User;
use App\Services\Connectors\FeexpayConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FriPay Link — service des liens de paiement partageables.
 *
 * Parcours :
 *   1. POST /payment-links          (auth)  -> création du lien (montant
 *      verrouillé + motif + expiration 48h par défaut).
 *   2. Le créateur partage l'URL publique /pay/{token}.
 *   3. GET  /payment-links/{token}  (public) -> infos du lien (montant,
 *      créateur masqué pour la vie privée).
 *   4. POST /payment-links/{token}/pay (public) -> initie la collecte
 *      FeexPay sur le téléphone du payeur (même mécanisme que la recharge).
 *   5. Webhook FeexPay OU vérification active -> crédit du wallet du
 *      créateur via le LEDGER, lien "paid", notification.
 *
 * Sécurité :
 * - montant verrouillé côté serveur (le payeur ne transmet jamais l'amount) ;
 * - token public non-devinable, jamais l'ID interne ;
 * - idempotence du crédit : double garde (statut du lien + index unique
 *   wallet_ledger_entries.transaction_id).
 */
class PaymentLinkService
{
    /** Longueur du token public (base62 — ~238 bits d'entropie). */
    private const TOKEN_LENGTH = 40;

    public function __construct(
        private readonly WalletService $wallets,
        private readonly FeexpayConnector $feexpay,
    ) {}

    /**
     * Crée un lien de paiement pour un utilisateur FriPay.
     */
    public function createLink(User $user, int $amount, ?string $description, int $ttlHours = PaymentLink::DEFAULT_TTL_HOURS): PaymentLink
    {
        return PaymentLink::create([
            'user_id'     => $user->id,
            'token'       => $this->generateToken(),
            'amount'      => $amount,
            'currency'    => 'XOF',
            'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
            'status'      => PaymentLink::STATUS_CREATED,
            'expires_at'  => now()->addHours(max(1, $ttlHours)),
        ]);
    }

    /**
     * URL publique complète du lien (pour le partage).
     */
    public function publicUrl(Request $request, PaymentLink $link): string
    {
        return rtrim(config('app.url') ?: $request->getSchemeAndHttpHost(), '/')
            . '/pay/' . $link->token;
    }

    /**
     * Initie le paiement FeexPay du lien par un payeur externe (sans compte).
     * Le montant est TOUJOURS celui du lien — jamais une entrée du payeur.
     */
    public function initiatePayment(PaymentLink $link, string $payerPhone, string $operator): array
    {
        if (! $link->isPayable()) {
            $reason = $link->status === PaymentLink::STATUS_PAID
                ? 'Ce lien a déjà été payé.'
                : ($link->status === PaymentLink::STATUS_CANCELLED
                    ? 'Ce lien a été annulé par son créateur.'
                    : 'Ce lien a expiré.');

            return ['accepted' => false, 'message' => $reason];
        }

        $phone = $this->normalizePhone($payerPhone);

        if ($phone === null) {
            return ['accepted' => false, 'message' => 'Numéro de téléphone invalide. Format attendu : +229 01 XX XX XX XX.'];
        }

        if (! in_array(strtoupper($operator), FeexpayConnector::supportedOperators(), true)) {
            return ['accepted' => false, 'message' => 'Opérateur non supporté (MTN ou Moov).'];
        }

        // Référence métier FriPay : sert de clé d'idempotence côté FeexPay
        // ET de traçabilité (audit) — sans jamais être l'ID du lien.
        $reference = 'LINK-' . strtoupper(Str::random(12));

        $result = $this->feexpay->requestToPay([
            'amount'      => (int) $link->amount, // montant VERROUILLÉ du lien
            'phone'       => ltrim($phone, '+'),
            'operator'    => strtoupper($operator),
            'first_name'  => 'Payeur',
            'last_name'   => 'FriPay Link',
            'email'       => '',
            'reference'   => $reference,
            'description' => 'Paiement lien FriPay ' . $reference,
        ]);

        if (! $result['success']) {
            Log::warning('FriPay Link : initiation FeexPay échouée', [
                'link'   => $link->id,
                'reason' => $result['message'],
            ]);

            return [
                'accepted' => false,
                'message'  => $result['message'],
                'retryable' => (bool) ($result['retryable'] ?? false),
            ];
        }

        // On mémorise la référence FeexPay : le webhook et les vérifications
        // de statut s'en servent pour retrouver CE lien précisément.
        $link->update(['provider_reference' => $result['reference']]);

        return [
            'accepted' => true,
            'message'  => 'Demande de paiement envoyée — validez sur votre téléphone (' . strtoupper($operator) . ').',
            'reference' => $reference,
        ];
    }

    /**
     * Marque le lien payé : crédit LEDGER idempotent du créateur +
     * notification. Sûr à appeler plusieurs fois (webhook rejoué, double
     * vérification, etc.) — jamais de double crédit ni de double notif.
     *
     * @return bool true si CET appel a effectué le paiement, false si le
     *              lien était déjà payé (ou dans un état non payable).
     */
    public function markLinkPaid(PaymentLink $link, string $payerPhone = '', ?string $payerOperator = null): bool
    {
        return DB::transaction(function () use ($link, $payerPhone, $payerOperator) {
            // Verrou pessimiste : deux confirmations concurrentes (webhook +
            // vérification active) ne peuvent pas passer simultanément.
            $locked = PaymentLink::whereKey($link->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status !== PaymentLink::STATUS_CREATED) {
                return false; // déjà payé / annulé / expiré : no-op
            }

            $locked->markPaid($payerPhone, $payerOperator);

            // Crédit via le LEDGER — source de vérité, jamais un solde
            // modifié sans écriture traçable. L'écriture porte payment_link_id
            // (transaction_id référencerait la table transactions via FK —
            // un lien n'est pas une transaction). L'idempotence est garantie
            // par le verrou pessimiste + test de statut ci-dessus.
            $this->wallets->credit(
                $locked->user_id,
                (float) $locked->amount,
                null,
                'payment_link_paid',
                'Paiement reçu via lien FriPay (' . substr($locked->token, 0, 8) . '…)',
                null,
                $locked->id
            );

            Notification::create([
                'user_id'                => $locked->user_id,
                'type'                   => 'transaction_update',
                'channel'                => 'in_app',
                'title'                  => 'Paiement reçu via votre lien',
                'body'                   => sprintf(
                    '%s FCFA vous ont été envoyés via votre lien FriPay%s. Votre solde a été crédité.',
                    number_format((float) $locked->amount, 0, ',', ' '),
                    $locked->description !== null ? ' — « ' . $locked->description . ' »' : ''
                ),
                'related_transaction_id' => null, // FK vers transactions : un lien n'en est pas une
                'read'                   => false,
            ]);

            Log::info('FriPay Link payé — wallet crédité', [
                'link'    => $locked->id,
                'amount'  => $locked->amount,
                'creator' => $locked->user_id,
            ]);

            return true;
        });
    }

    /**
     * Retrouve un lien par sa référence FeexPay (webhook) puis le marque
     * payé après CONFIRMATION auprès de l'API FeexPay (source de vérité —
     * un callback forgé ne peut pas créditer un wallet).
     */
    public function confirmByProviderReference(string $providerReference): ?PaymentLink
    {
        $link = PaymentLink::where('provider_reference', $providerReference)->first();

        if (! $link) {
            return null;
        }

        if (! $this->refreshFromProvider($link)) {
            return $link->fresh();
        }

        return $link->fresh();
    }

    /**
     * Vérifie le statut du paiement auprès de FeexPay (source de vérité) et
     * confirme le paiement si SUCCESSFUL. Idempotent.
     */
    public function refreshFromProvider(PaymentLink $link): bool
    {
        if (! $link->provider_reference || $link->status !== PaymentLink::STATUS_CREATED) {
            return false;
        }

        $status = $this->feexpay->getStatus($link->provider_reference);

        if (($status['status'] ?? null) === FeexpayConnector::STATUS_SUCCESSFUL) {
            return $this->markLinkPaid(
                $link,
                (string) ($status['clientNum'] ?? ''),
                null
            );
        }

        return false;
    }

    /**
     * Token public non-devinable : 40 caractères aléatoires en base62.
     * Jamais un ID séquentiel ou UUID (prévisible) dans l'URL.
     */
    private function generateToken(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $token = '';
        $max = strlen($alphabet) - 1;

        for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
            $token .= $alphabet[random_int(0, $max)];
        }

        // Garantie d'unicité en base (théoriquement impossible à violer,
        // mais on ne prend aucun risque).
        while (PaymentLink::where('token', $token)->exists()) {
            $token = $this->generateToken();
        }

        return $token;
    }

    /**
     * Normalise un numéro béninois en MSISDN international (+229...).
     */
    private function normalizePhone(string $raw): ?string
    {
        $phone = preg_replace('/[\s\-\.]/', '', trim($raw));

        if ($phone === '' || $phone === null) {
            return null;
        }

        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }

        if (! str_starts_with($phone, '+')) {
            // Numéro local : on préfixe par l'indicatif du Bénin.
            $phone = '+229' . ltrim($phone, '0');
        }

        return preg_match('/^\+\d{8,15}$/', $phone) ? $phone : null;
    }
}
