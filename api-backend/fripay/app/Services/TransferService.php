<?php

namespace App\Services;

use App\Models\Corridor;
use App\Models\Notification;
use App\Models\PendingTransfer;
use App\Models\Transaction;
use App\Models\TransactionStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TransferService
{
    /** Statuts terminaux : une transaction qui y est ne change plus jamais d'état. */
    public const FINAL_STATUSES = ['succeeded', 'failed', 'cancelled', 'completed'];

    private ConnectorRegistry $connectors;
    private OperatorDetectionService $operatorDetection;
    private WalletService $wallets;
    private FripayNumberService $fripayNumbers;

    public function __construct()
    {
        $this->connectors         = app(ConnectorRegistry::class);
        $this->operatorDetection  = app(OperatorDetectionService::class);
        $this->wallets            = app(WalletService::class);
        $this->fripayNumbers      = app(FripayNumberService::class);
    }

    /**
     * Calculate a quote for a potential transfer.
     * Utilise le corridor le plus prioritaire pour la paire d'opérateurs.
     */
    public function calculateQuote(string $senderAccountId, string $recipientPhone, float $amount): array
    {
        // Destinataire compte Fripay (numéro 30 + 8 chiffres) : virement
        // interne wallet-à-wallet, sans corridor opérateur ni frais. Avant
        // cette branche, calculateQuote levait OPERATOR_NOT_SUPPORTED pour
        // tout numéro Fripay (le préfixe 30 n'est pas dans phone_prefixes).
        if ($this->fripayNumbers->isFripayNumber($recipientPhone)) {
            return $this->buildInternalQuote($senderAccountId, $recipientPhone, $amount);
        }

        $recipientOperator = $this->operatorDetection->detect($recipientPhone);

        if (!$recipientOperator) {
            throw new \RuntimeException('OPERATOR_NOT_SUPPORTED');
        }

        // Chercher le corridor actif le plus prioritaire pour cet opérateur destinataire.
        $corridor = Corridor::where('destination_operator_id', $recipientOperator->id)
            ->where('active', true)
            ->orderBy('priority')
            ->first();

        if (!$corridor) {
            throw new \RuntimeException('NO_ROUTE_AVAILABLE');
        }

        // Valider le montant
        if ($amount < $corridor->min_amount || $amount > $corridor->max_amount) {
            throw new \RuntimeException('AMOUNT_OUT_OF_RANGE');
        }

        // Calculer les frais selon la règle du corridor
        $feeAmount = $this->calculateFee($corridor, $amount);

        // Délai de livraison estimé par défaut (le connecteur pourra le préciser)
        $deliverySeconds = 30;

        $quoteToken = Str::random(32);

        cache()->put('quote_' . $quoteToken, [
            'sender_account_id'     => $senderAccountId,
            'recipient_phone'       => $recipientPhone,
            'amount'                => $amount,
            'fee_amount'            => $feeAmount,
            'total_debited'         => $amount + $feeAmount,
            'recipient_operator_id' => $recipientOperator->id,
            'corridor_id'           => $corridor->id,
            'rail'                  => $corridor->rail,
            'aggregator_provider'   => $corridor->aggregator_provider,
            'expires_at'            => now()->addMinutes(2),
        ], 180);

        return [
            'recipient_operator'       => $recipientOperator->code,
            'recipient_name'           => null,
            'amount'                   => $amount,
            'fee_amount'               => $feeAmount,
            'total_debited'            => $amount + $feeAmount,
            'rail'                     => $corridor->rail,
            'aggregator_provider'      => $corridor->aggregator_provider,
            'estimated_delivery_seconds' => $deliverySeconds,
            'quote_token'              => $quoteToken,
        ];
    }

    /**
     * Quote pour un virement INTERNE Fripay (wallet -> wallet) : pas de
     * corridor, pas de frais, réglé immédiatement à l'initiation.
     */
    private function buildInternalQuote(string $senderAccountId, string $recipientPhone, float $amount): array
    {
        if ($amount < 100 || $amount > 5000000) {
            throw new \RuntimeException('AMOUNT_OUT_OF_RANGE');
        }

        $quoteToken = Str::random(32);

        cache()->put('quote_' . $quoteToken, [
            'sender_account_id'     => $senderAccountId,
            'recipient_phone'       => $recipientPhone,
            'amount'                => $amount,
            'fee_amount'            => 0,
            'total_debited'         => $amount,
            'recipient_operator_id' => null,
            'corridor_id'           => null,
            'rail'                  => 'fripay_internal',
            'aggregator_provider'   => null,
            'expires_at'            => now()->addMinutes(2),
        ], 180);

        return [
            'recipient_operator'       => 'FRIPAY',
            'recipient_name'           => null,
            'amount'                   => $amount,
            'fee_amount'               => 0,
            'total_debited'            => $amount,
            'rail'                     => 'fripay_internal',
            'aggregator_provider'      => null,
            'estimated_delivery_seconds' => 5,
            'quote_token'              => $quoteToken,
        ];
    }

    /**
     * Initiate a transfer.
     *
     * Le transfert est accepté et enregistré localement en statut 'pending'
     * AVANT tout appel externe : il fonctionne donc même si le connecteur du
     * réseau (API native MTN, Moov, Celtiis ou agrégateur) est injoignable
     * ou pas encore intégré. Dans ce cas il est mis en file d'attente locale
     * (outbox) et sera exécuté dès qu'un connecteur sera disponible.
     */
    public function initiate(string $quoteToken, string $senderAccountId, string $recipientPhone, float $amount): Transaction
    {
        $quote = cache()->get('quote_' . $quoteToken);

        if (!$quote) {
            throw new \RuntimeException('QUOTE_EXPIRED');
        }

        if ($quote['expires_at'] < now()) {
            cache()->forget('quote_' . $quoteToken);
            throw new \RuntimeException('QUOTE_EXPIRED');
        }

        if ($quote['sender_account_id'] !== $senderAccountId ||
            $quote['recipient_phone'] !== $recipientPhone ||
            $quote['amount'] != $amount) {
            throw new \RuntimeException('QUOTE_MISMATCH');
        }

        cache()->forget('quote_' . $quoteToken);

        $reference = 'TXN-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        $rail = $quote['rail'] ?? 'aggregator';
        $senderUserId = auth()->id();

        // Le receveur est-il lui-même un utilisateur FriPay (numéro
        // opérateur ou numéro FriPay déjà enregistré) ? Si oui, c'est un
        // virement INTERNE wallet-à-wallet : il est réglé immédiatement,
        // sans passer par un connecteur externe (MTN/Moov/Celtiis) — ces
        // connecteurs ne sont d'ailleurs pas configurés (clés API vides),
        // donc un transfert vers un compte FriPay ne partait jamais et
        // restait bloqué indéfiniment en file d'attente.
        $recipientUser = $this->resolveRecipientUser($recipientPhone);

        // Débit du wallet AVANT création de la transaction : si le solde est
        // insuffisant, on échoue immédiatement (RuntimeException INSUFFICIENT_FUNDS)
        // sans rien enregistrer. Le débit (et le crédit interne éventuel) et
        // la création de la transaction sont atomiques (même transaction DB)
        // pour éviter tout état incohérent.
        $transaction = \Illuminate\Support\Facades\DB::transaction(function () use (
            $reference, $senderAccountId, $recipientPhone, $amount, $quote, $rail, $senderUserId, $recipientUser
        ) {
            $transaction = Transaction::create([
                'reference'             => $reference,
                'idempotency_key'       => request()->header('Idempotency-Key', Str::uuid()),
                'sender_user_id'        => $senderUserId,
                'sender_account_id'     => $senderAccountId,
                'recipient_phone'       => $recipientPhone,
                // null = virement interne Fripay (pas de corridor opérateur) ;
                // la colonne est nullable et sans valeur par défaut.
                'recipient_operator_id' => $quote['recipient_operator_id'] ?? null,
                'amount'                => $amount,
                'currency'              => 'XOF',
                'fee_amount'            => $quote['fee_amount'],
                'total_debited'         => $quote['total_debited'],
                'rail_used'             => $recipientUser ? 'fripay_internal' : $rail,
                'aggregator_provider'   => $quote['aggregator_provider'] ?? null,
                'corridor_id'           => $quote['corridor_id'] ?? null,
                'status'                => 'pending',
                'client_type_snapshot'  => auth()->user()->client_type,
                'initiated_at'          => now(),
                'metadata'              => $recipientUser ? ['recipient_user_id' => $recipientUser->id] : null,
            ]);

            $this->wallets->debit(
                $senderUserId,
                (float) $quote['total_debited'],
                $transaction->id,
                'transfer_out',
                "Transfert {$transaction->reference}"
            );

            if ($recipientUser) {
                // Virement interne réglé sur-le-champ : le receveur touche
                // le montant net (les frais restent acquis à FriPay).
                $this->wallets->credit(
                    $recipientUser->id,
                    (float) $amount,
                    $transaction->id,
                    'transfer_in',
                    "Réception transfert {$transaction->reference}"
                );

                $transaction->update(['status' => 'completed', 'completed_at' => now()]);
            }

            return $transaction;
        });

        if ($recipientUser) {
            $this->recordHistory($transaction, 'pending', 'completed', 'system', 'Transfert interne FriPay réglé immédiatement');

            $amountLabel = number_format($amount, 0, ',', ' ') . ' FCFA';
            $this->notify($senderUserId, "Transfert envoyé", "Vous avez envoyé {$amountLabel}.", $transaction->id);
            $this->notify($recipientUser->id, 'Argent reçu', "Vous avez reçu {$amountLabel} sur votre compte FriPay.", $transaction->id);
        } else {
            $this->recordHistory($transaction, null, 'pending', 'system', 'Transfert initié');
            $this->dispatch($transaction, $recipientPhone);
        }

        return $transaction;
    }

    /**
     * Résout le destinataire d'un transfert vers un compte FriPay existant,
     * par numéro d'opérateur (celui utilisé à l'inscription) ou par numéro
     * FriPay (préfixe 30, cahier des charges §1). Retourne null si le
     * numéro ne correspond à aucun compte FriPay — le transfert part alors
     * en externe (MTN/Moov/Celtiis).
     */
    private function resolveRecipientUser(string $recipientPhone): ?User
    {
        if ($this->fripayNumbers->isFripayNumber($recipientPhone)) {
            return User::where('fripay_number', $this->fripayNumbers->normalize($recipientPhone))->first();
        }

        // Bug corrigé : le numéro entré par l'expéditeur (ex. "0167125906")
        // n'est pas dans le même format que celui stocké en base à
        // l'inscription (ex. "+22901..."). Sans normalisation ici, la
        // comparaison échouait presque toujours -> $recipientUser restait
        // null -> TOUS les transferts (même FriPay -> FriPay) tombaient
        // dans la branche "externe" et restaient bloqués en 'pending'.
        return User::where('phone_number', $this->operatorDetection->normalize($recipientPhone))->first();
    }

    /**
     * Crée une notification in-app pour l'utilisateur donné (table partagée
     * `notifications`, cf. App\Models\Notification dans fripay-common).
     */
    private function notify(string $userId, string $title, string $body, ?string $transactionId): void
    {
        Notification::create([
            'user_id'                => $userId,
            'type'                   => 'transaction_update',
            'channel'                => 'in_app',
            'title'                  => $title,
            'body'                   => $body,
            'related_transaction_id' => $transactionId,
            'read'                   => false,
        ]);
    }

    /**
     * Résout et appelle le connecteur adapté (API native du réseau GSM ou
     * agrégateur). En l'absence de connecteur, le transfert est mis en file
     * d'attente (outbox) : il sera exécuté dès qu'un connecteur sera
     * configuré.
     */
    private function dispatch(Transaction $transaction, string $recipientPhone): void
    {
        // Virement interne Fripay : jamais de connecteur externe. Ne devrait
        // pas arriver (initiate() règle ces transferts sur-le-champ), mais
        // on blinde au cas où le flux serait réutilisé.
        if ($transaction->rail_used === 'fripay_internal') {
            return;
        }

        $recipientOperator = $this->operatorDetection->detect($recipientPhone);
        $operatorCode = $recipientOperator ? $recipientOperator->code : 'UNKNOWN';

        $payload = [
            'amount'          => (int) $transaction->amount,
            'recipient_phone' => $recipientPhone,
            'reference'       => $transaction->reference,
            'operator_code'   => $operatorCode,
            'description'     => 'Transfert FriPay',
        ];

        $connector = $this->connectors->resolve($transaction);

        if (!$connector || !$connector->isConfigured()) {
            $reason = !$connector
                ? "Aucun connecteur configuré pour le réseau {$operatorCode}"
                : 'Connecteur non configuré (clés API manquantes)';

            $this->enqueuePendingTransfer($transaction, $payload, $reason);

            return;
        }

        $result = $connector->initiateTransfer($payload);

        if ($result['success']) {
            $this->markProcessing($transaction, $result['transaction_id'] ?? null);

            return;
        }

        // IP serveur non whitelistée chez FeexPay : aucun retry n'aboutira
        // tant que l'IP n'est pas déclarée dans le dashboard marchand —
        // échec immédiat + remboursement (le payout retentera en cas de
        // rejeu manuel une fois l'IP déclarée). Diag 05/10/2026 : sans ce
        // cas, 10 tentatives à vide via l'outbox.
        if (! empty($result['ip_not_allowed'])) {
            $this->markFailed($transaction, $result['message']);

            return;
        }

        // Rejet métier définitif (4xx) -> échec immédiat.
        if (($result['retryable'] ?? true) === false) {
            $this->markFailed($transaction, $result['message']);

            return;
        }

        // Erreur réseau / 5xx / 429 -> file d'attente (mode hors-ligne).
        $this->enqueuePendingTransfer($transaction, $payload, $result['message']);
    }

    /**
     * Filet anti-limbo : échoue (avec remboursement idempotent) les payouts
     * restés en « processing » plus de $hours heures, SANS référence
     * consultable chez l'agrégateur.
     *
     * FeexPay n'expose aucun endpoint public de statut payout (404 sur
     * toutes les routes testées, diag 05/10/2026) : sans webhook, le sort
     * d'un payout est définitivement inconnu. Mieux vaut rembourser le
     * client (qui peut retenter) que de laisser son argent bloqué à vie.
     *
     * Idempotent : ne vise que statut = processing.
     */
    public function failStalePayouts(int $hours = 24, int $limit = 100): array
    {
        $cutoff = now()->subHours($hours);

        $stale = Transaction::query()
            ->where('status', 'processing')
            ->where('rail_used', 'aggregator')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $failed = 0;

        foreach ($stale as $transaction) {
            $previous = $transaction->status;

            $transaction->update([
                'status'         => 'failed',
                'failure_reason' => "Payout non confirmé par l'agrégateur depuis plus de {$hours} h — remboursé par sécurité",
                'completed_at'   => now(),
            ]);

            $this->recordHistory($transaction, $previous, 'failed', 'system', 'Filet anti-limbo : statut payout inconnu (> 24 h)');
            $this->refundWallet($transaction, 'transfer_refund_payout_stale');

            Log::warning('Payout expiré sans confirmation — remboursé (anti-limbo)', [
                'transaction' => $transaction->id,
                'reference'   => $transaction->reference,
                'depuis'      => (string) $transaction->updated_at,
            ]);

            $failed++;
        }

        return ['failed' => $failed];
    }

    /**
     * Met un transfert en file d'attente locale (outbox).
     */
    private function enqueuePendingTransfer(Transaction $transaction, array $payload, string $error): void
    {
        PendingTransfer::create([
            'transaction_id' => $transaction->id,
            'payload'        => $payload,
            'status'         => 'pending',
            'attempts'       => 0,
            'max_attempts'   => (int) config('fripay.outbox.max_attempts', 10),
            'next_retry_at'  => now(),
            'last_error'     => $error,
        ]);

        Log::warning('Transfert accepté hors-ligne — mis en file d\'attente', [
            'transaction' => $transaction->id,
            'error'       => $error,
        ]);
    }

    /**
     * Traite les transferts en file d'attente dont l'échéance est atteinte.
     *
     * Appelée par la commande `transfers:process-pending` (scheduler) et de
     * façon opportuniste par GET /transfers : le transfert part dès qu'un
     * connecteur est disponible, sans infrastructure supplémentaire.
     */
    public function processPendingTransfers(?int $limit = null): array
    {
        if (!config('fripay.outbox.enabled', true)) {
            return ['processed' => 0, 'skipped' => true, 'reason' => 'outbox_disabled'];
        }

        $limit ??= (int) config('fripay.outbox.batch_size', 10);

        // Verrou atomique : évite deux exécutions concurrentes.
        $lock = Cache::lock('fripay:outbox:lock', 60);

        if (!$lock->get()) {
            return ['processed' => 0, 'skipped' => true, 'reason' => 'already_running'];
        }

        try {
            // Items à traiter : 'pending', ou 'processing' obsolète (processeur
            // interrompu en plein vol, ex. crash) dont l'échéance est atteinte.
            $items = PendingTransfer::query()
                ->where(function ($q) {
                    $q->where('status', 'pending')
                        ->orWhere(function ($q2) {
                            $q2->where('status', 'processing')
                                ->where('updated_at', '<', now()->subMinutes(10));
                        });
                })
                ->whereColumn('attempts', '<', 'max_attempts')
                ->where('next_retry_at', '<=', now())
                ->orderBy('next_retry_at')
                ->limit($limit)
                ->get();

            $processed = 0;
            foreach ($items as $item) {
                $this->processPendingTransfer($item);
                $processed++;
            }

            return ['processed' => $processed, 'skipped' => false];
        } finally {
            $lock->release();
        }
    }

    /**
     * Tente d'exécuter un transfert différé via le connecteur adapté.
     */
    private function processPendingTransfer(PendingTransfer $item): void
    {
        $transaction = $item->transaction;

        // Déjà finalisée (webhook, annulation) -> plus rien à faire.
        if (!$transaction || $transaction->status !== 'pending') {
            $item->update([
                'status'     => 'completed',
                'last_error' => $transaction
                    ? "Transaction déjà traitée (statut: {$transaction->status})"
                    : 'Transaction introuvable',
            ]);

            return;
        }

        $connector = $this->connectors->resolve($transaction);

        // Aucun connecteur (API opérateur pas encore intégrée) : on ne
        // consomme pas de tentative, on re-teste plus tard.
        if (!$connector || !$connector->isConfigured()) {
            $operatorCode = $transaction->recipientOperator?->code ?? 'inconnu';

            $item->update([
                'status'        => 'pending',
                'next_retry_at' => now()->addSeconds((int) config('fripay.outbox.no_connector_retry_seconds', 3600)),
                'last_error'    => !$connector
                    ? "Aucun connecteur configuré (opérateur : {$operatorCode})"
                    : 'Connecteur non configuré (clés API manquantes)',
            ]);

            return;
        }

        $item->update(['status' => 'processing', 'last_error' => null]);
        $item->increment('attempts');

        $result = $connector->initiateTransfer($item->payload);

        if ($result['success']) {
            $this->markProcessing($transaction, $result['transaction_id'] ?? null);
            $item->update(['status' => 'completed']);

            return;
        }

        // IP serveur non whitelistée chez FeexPay : aucun retry n'aboutira
        // tant que l'IP n'est pas déclarée dans le dashboard marchand —
        // échec immédiat (avec remboursement) au lieu d'épuiser le backoff
        // exponentiel pendant des jours (60 s -> 24 h sur 10 tentatives).
        if (! empty($result['ip_not_allowed'])) {
            $this->markFailed($transaction, $result['message']);
            $item->update(['status' => 'completed', 'last_error' => $result['message']]);

            return;
        }

        // Rejet définitif OU tentatives épuisées -> échec.
        if (($result['retryable'] ?? true) === false || $item->attempts >= $item->max_attempts) {
            $this->markFailed($transaction, $result['message']);
            $item->update(['status' => 'completed', 'last_error' => $result['message']]);

            return;
        }

        // Nouvel échec retryable -> backoff exponentiel.
        $backoff = $this->retryDelaySeconds($item->attempts);
        $item->update([
            'status'        => 'pending',
            'next_retry_at' => now()->addSeconds($backoff),
            'last_error'    => $result['message'],
        ]);

        Log::warning('Transfert différé — prochaine tentative planifiée', [
            'transaction' => $transaction->id,
            'attempt'     => $item->attempts,
            'retry_in'    => $backoff,
            'error'       => $result['message'],
        ]);
    }

    /**
     * Backoff exponentiel : base * 2^(tentative-1), plafonné.
     */
    private function retryDelaySeconds(int $attempts): int
    {
        $base = (int) config('fripay.outbox.backoff_base_seconds', 60);
        $max  = (int) config('fripay.outbox.backoff_max_seconds', 86400);

        return min($max, $base * (2 ** max(0, $attempts - 1)));
    }

    /**
     * Marque la transaction comme envoyée au réseau (en attente de webhook).
     */
    private function markProcessing(Transaction $transaction, ?string $externalReference): void
    {
        DB::transaction(function () use ($transaction, $externalReference) {
            $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            // Le webhook peut arriver AVANT cette mise à jour (réponse FeexPay
            // lente) et avoir déjà finalisé la transaction : on ne repasse
            // jamais un statut final en « processing », on garde seulement
            // la référence réseau.
            if (in_array($locked->status, self::FINAL_STATUSES, true)) {
                if ($externalReference && ! $locked->external_reference) {
                    $locked->update(['external_reference' => $externalReference]);
                }

                return;
            }

            $previous = $locked->status;

            $locked->update([
                'status'             => 'processing',
                'external_reference' => $externalReference ?: $locked->external_reference,
            ]);

            $this->recordHistory($locked, $previous, 'processing', 'system', 'Envoyé au réseau');
        });
    }

    /**
     * UNIQUE point de finalisation d'un payout (succeeded | failed).
     *
     * Verrouille la ligne transaction, refuse de toucher à un statut déjà
     * final (idempotent : un webhook rejoué ou un polling concurrent ne
     * fait rien) et, pour un échec, rembourse DANS LA MÊME transaction DB.
     *
     * @return bool true si cet appel a finalisé la transaction, false si
     *              elle était déjà finale (ou introuvable).
     */
    public function finalize(string $transactionId, string $status, string $source, string $note, ?string $refundReason = null): bool
    {
        if (! in_array($status, ['succeeded', 'failed'], true)) {
            throw new \InvalidArgumentException("Statut final invalide : {$status}");
        }

        $transaction = null;

        $done = DB::transaction(function () use ($transactionId, $status, $source, $note, $refundReason, &$transaction) {
            $transaction = Transaction::whereKey($transactionId)->lockForUpdate()->first();

            if (! $transaction || in_array($transaction->status, self::FINAL_STATUSES, true)) {
                return false;
            }

            $previous = $transaction->status;

            $transaction->update([
                'status'         => $status,
                'completed_at'   => now(),
                'failure_reason' => $status === 'failed' ? $note : $transaction->failure_reason,
            ]);

            $this->recordHistory($transaction, $previous, $status, $source, $note);

            if ($status === 'failed') {
                $this->refundWallet($transaction, $refundReason ?? 'transfer_refund_failed');
            }

            return true;
        });

        if ($done && $transaction) {
            $label = number_format((float) $transaction->amount, 0, ',', ' ') . ' FCFA';

            $this->notify(
                $transaction->sender_user_id,
                $status === 'succeeded' ? 'Transfert délivré' : 'Transfert échoué',
                $status === 'succeeded'
                    ? "Votre transfert de {$label} a été délivré."
                    : "Votre transfert de {$label} n'a pas pu être délivré. Le montant a été remboursé sur votre compte.",
                $transaction->id
            );
        }

        return $done;
    }

    /**
     * Applique un webhook FeexPay « payout » (SUCCESSFUL | FAILED).
     *
     * La transaction est retrouvée par la référence FeexPay
     * (external_reference) ou, si le webhook devance markProcessing(), par
     * `callback_info` (= référence FriPay envoyée au payout).
     *
     * @return Transaction|null null si aucun payout ne correspond.
     */
    public function applyFeexpayPayoutWebhook(array $payload, string $providerReference): ?Transaction
    {
        $fripayReference = (string) ($payload['callback_info'] ?? '');

        $transaction = Transaction::query()
            ->where('rail_used', 'aggregator')
            ->where(fn ($q) => $q->whereNull('metadata')->orWhereJsonDoesntContain('metadata->kind', 'topup'))
            ->where(function ($q) use ($providerReference, $fripayReference) {
                $q->where('external_reference', $providerReference);

                if ($fripayReference !== '') {
                    $q->orWhere('reference', $fripayReference);
                }
            })
            ->first();

        if (! $transaction) {
            return null;
        }

        if (! $transaction->external_reference) {
            $transaction->update(['external_reference' => $providerReference]);
        }

        $status = match (strtoupper((string) ($payload['status'] ?? ''))) {
            'SUCCESSFUL', 'SUCCESS' => 'succeeded',
            'FAILED'                => 'failed',
            default                 => null, // PENDING / inconnu : rien à finaliser
        };

        if ($status === null) {
            return $transaction;
        }

        $message = trim((string) ($payload['message'] ?? ''));

        $this->finalize(
            $transaction->id,
            $status,
            'webhook',
            $status === 'failed'
                ? 'Payout FeexPay échoué' . ($message !== '' ? " : {$message}" : '')
                : 'Payout FeexPay confirmé par webhook',
            'transfer_refund_payout_failed'
        );

        return $transaction->fresh();
    }

    /**
     * Marque la transaction comme échouée.
     */
    private function markFailed(Transaction $transaction, string $reason): void
    {
        $previous = $transaction->status;

        $transaction->update([
            'status'         => 'failed',
            'failure_reason' => $reason,
            'completed_at'   => now(),
        ]);

        // Remboursement automatique du wallet : le montant débité à
        // l'initiation (montant + frais) est recrédité puisque le transfert
        // n'a finalement pas pu être exécuté.
        $this->refundWallet($transaction, 'transfer_refund_failed');

        $this->recordHistory($transaction, $previous, 'failed', 'system', $reason);

        $this->notify(
            $transaction->sender_user_id,
            'Transfert échoué',
            'Votre transfert de ' . number_format((float) $transaction->amount, 0, ',', ' ') . ' FCFA n\'a pas pu être délivré. Le montant a été remboursé sur votre compte.',
            $transaction->id
        );
    }

    /**
     * Recrédite le wallet de l'expéditeur l'exact inverse du mouvement
     * enregistré dans le LEDGER (WalletLedgerEntry) pour cette transaction.
     *
     * Le ledger est la source de vérité : on ne rembourse JAMAIS un montant
     * recalculé (total_debited) mais le net réellement débité. Garanties :
     * - aucun débit au ledger pour cette transaction => AUCUN crédit (on ne
     *   crée pas d'argent — c'était le bug "remboursement aveugle" : une
     *   annulation ajoutait total_debited alors que rien n'avait été débité,
     *   ex. solde 10000 -> 11000 après annulation d'un paiement de 100) ;
     * - débit partiel / double écriture => on rembourse le net exact, ce qui
     *   ramène le solde à sa valeur d'avant initiation ;
     * - remboursement déjà enregistré => net = 0 => appel idempotent.
     */
    public function refundWallet(Transaction $transaction, string $reason): void
    {
        $wallet = $this->wallets->getOrCreate($transaction->sender_user_id);

        $netDebited = $this->wallets->netMovementForTransaction($wallet->id, $transaction->id);

        if ($netDebited <= 0) {
            // Rien n'a été débité pour cette transaction (ou déjà remboursé) :
            // on annule le statut mais on ne crée PAS d'argent.
            Log::info('Remboursement ignoré — aucun débit ledger correspondant', [
                'transaction' => $transaction->id,
                'reference'   => $transaction->reference,
                'net'         => $netDebited,
                'reason'      => $reason,
            ]);

            return;
        }

        $this->wallets->credit(
            $transaction->sender_user_id,
            $netDebited,
            $transaction->id,
            $reason,
            "Remboursement transfert {$transaction->reference}"
        );
    }

    /**
     * Enregistre un changement de statut dans l'historique.
     */
    private function recordHistory(Transaction $transaction, ?string $previousStatus, string $newStatus, string $source, string $note): void
    {
        TransactionStatusHistory::create([
            'transaction_id'   => $transaction->id,
            'previous_status'  => $previousStatus,
            'new_status'       => $newStatus,
            'source'           => $source,
            'note'             => $note,
        ]);
    }

    /**
     * Calcule les frais selon la règle du corridor (fixed | percentage | tiered).
     */
    private function calculateFee(Corridor $corridor, float $amount): float
    {
        $fee = match ($corridor->fee_type) {
            'fixed'      => (float) $corridor->fee_value,
            'percentage' => $amount * ((float) $corridor->fee_value / 100),
            'tiered'     => (float) $corridor->fee_value,
            default      => 0.0,
        };

        if ($corridor->fee_cap && $fee > (float) $corridor->fee_cap) {
            $fee = (float) $corridor->fee_cap;
        }

        return round($fee, 2);
    }

    /**
     * Cancel a transaction if possible.
     *
     * Deux cas :
     * - 'initiated' / 'pending' : rien (ou une partie) n'a quitté le wallet
     *   de manière définitive -> remboursement du net débité au ledger
     *   (souvent 0 : aucune création d'argent, cf. refundWallet).
     * - 'completed' réglé en INTERNE (QR P2P / virement FriPay->FriPay) :
     *   l'argent a réellement circulé entre deux wallets FriPay ->
     *   annulation = inversion SYMÉTRIQUE : l'expéditeur récupère l'exact
     *   inverse de son débit (ledger) ET le bénéficiaire rend l'exact
     *   inverse de son crédit (ledger). Si le bénéficiaire a déjà dépensé
     *   les fonds (solde insuffisant), l'annulation est refusée.
     */
    public function cancel(Transaction $transaction): Transaction
    {
        $isInternalSettled = $this->isInternallySettled($transaction);

        if (!in_array($transaction->status, ['initiated', 'pending'], true) && !$isInternalSettled) {
            throw new \RuntimeException('TRANSACTION_NOT_CANCELLABLE');
        }

        // Validation AVANT tout changement d'état : si le bénéficiaire ne
        // peut pas rendre les fonds, la transaction reste dans son état.
        if ($isInternalSettled) {
            $this->assertInternalSettlementReversible($transaction);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($transaction, $isInternalSettled) {
            $previousStatus = $transaction->status;
            $transaction->update(['status' => 'cancelled']);

            if ($isInternalSettled) {
                $this->reverseInternalSettlement($transaction);
            } else {
                $this->refundWallet($transaction, 'transfer_refund_cancelled');
            }

            $this->recordHistory($transaction, $previousStatus, 'cancelled', 'user', "Annulé par l'utilisateur");
        });

        return $transaction;
    }

    /**
     * La transaction a-t-elle été réglée entre deux wallets Fripay
     * (rail interne) ? Dans ce cas une annulation est une inversion
     * symétrique, pas un simple remboursement.
     */
    private function isInternallySettled(Transaction $transaction): bool
    {
        if ($transaction->status !== 'completed') {
            return false;
        }

        $rail = (string) ($transaction->rail_used ?? '');

        return $rail === 'fripay_internal' || str_starts_with($rail, 'qr_');
    }

    /**
     * ID du bénéficiaire d'un règlement interne (métadonnées).
     */
    private function internalRecipientId(Transaction $transaction): ?string
    {
        $meta = $transaction->metadata ?? [];

        return $meta['recipient_user_id'] ?? $meta['merchant_id'] ?? null;
    }

    /**
     * Vérifie que l'inversion d'un règlement interne est possible :
     * le bénéficiaire a bien reçu un crédit au ledger pour cette
     * transaction ET dispose encore des fonds pour le rendre.
     */
    private function assertInternalSettlementReversible(Transaction $transaction): void
    {
        $recipientId = $this->internalRecipientId($transaction);

        if (!$recipientId) {
            throw new \RuntimeException('TRANSACTION_NOT_CANCELLABLE');
        }

        $recipientWallet = $this->wallets->getOrCreate($recipientId);
        $netCredited = -$this->wallets->netMovementForTransaction($recipientWallet->id, $transaction->id);

        if ($netCredited <= 0) {
            // Aucun crédit tracé au ledger : rien à inverser côté
            // bénéficiaire (l'expéditeur sera remboursé du net débité).
            return;
        }

        if ((float) $recipientWallet->balance < $netCredited) {
            // Le bénéficiaire a déjà dépensé les fonds : on ne crée pas
            // d'argent — l'annulation est refusée.
            throw new \RuntimeException('TRANSACTION_NOT_CANCELLABLE');
        }
    }

    /**
     * Inversion symétrique d'un règlement interne : remboursement de
     * l'expéditeur (exact inverse de son débit, via refundWallet) +
     * prélèvement du bénéficiaire (exact inverse de son crédit, via le
     * ledger). Les frais restent acquis à la plateforme, symétriquement
     * à l'opération d'origine.
     */
    private function reverseInternalSettlement(Transaction $transaction): void
    {
        // 1) L'expéditeur récupère ce qu'il a réellement débité.
        $this->refundWallet($transaction, 'transfer_refund_cancelled');

        // 2) Le bénéficiaire rend ce qu'il a réellement reçu (ledger).
        $recipientId = $this->internalRecipientId($transaction);

        if (!$recipientId) {
            return;
        }

        $recipientWallet = $this->wallets->getOrCreate($recipientId);
        $netCredited = -$this->wallets->netMovementForTransaction($recipientWallet->id, $transaction->id);

        if ($netCredited <= 0) {
            return;
        }

        $this->wallets->debit(
            $recipientId,
            $netCredited,
            $transaction->id,
            'transfer_reversal_out',
            "Annulation {$transaction->reference} — reprise du crédit"
        );
    }
}