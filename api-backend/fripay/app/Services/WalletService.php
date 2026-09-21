<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Récupère le wallet de l'utilisateur, le crée s'il n'existe pas encore
     * (solde 0, devise XOF).
     */
    public function getOrCreate(string $userId): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $userId],
            ['balance' => 0, 'currency' => 'XOF', 'status' => 'active']
        );
    }

    public function getBalance(string $userId): float
    {
        return (float) $this->getOrCreate($userId)->balance;
    }

    /**
     * Débite le wallet d'un montant. Atomique + verrou de ligne pour éviter
     * les débits concurrents (double dépense). Lève INSUFFICIENT_FUNDS si le
     * solde est insuffisant.
     */
    public function debit(string $userId, float $amount, ?string $transactionId, string $reason, ?string $description = null, ?int $offlineQrCodeId = null, ?string $paymentLinkId = null): Wallet
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('AMOUNT_MUST_BE_POSITIVE');
        }

        return DB::transaction(function () use ($userId, $amount, $transactionId, $reason, $description, $offlineQrCodeId, $paymentLinkId) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();

            if (!$wallet) {
                $wallet = $this->getOrCreate($userId);
                $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
            }

            if ((float) $wallet->balance < $amount) {
                throw new \RuntimeException('INSUFFICIENT_FUNDS');
            }

            $newBalance = round((float) $wallet->balance - $amount, 2);
            $wallet->update(['balance' => $newBalance]);

            WalletLedgerEntry::create([
                'wallet_id' => $wallet->id,
                'transaction_id' => $transactionId,
                'offline_qr_code_id' => $offlineQrCodeId,
                'payment_link_id' => $paymentLinkId,
                'type' => 'debit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'description' => $description,
                'created_at' => now(),
            ]);

            return $wallet;
        });
    }

    /**
     * Crédite le wallet d'un montant (dépôt, remboursement, réception).
     */
    public function credit(string $userId, float $amount, ?string $transactionId, string $reason, ?string $description = null, ?int $offlineQrCodeId = null, ?string $paymentLinkId = null): Wallet
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('AMOUNT_MUST_BE_POSITIVE');
        }

        return DB::transaction(function () use ($userId, $amount, $transactionId, $reason, $description, $offlineQrCodeId, $paymentLinkId) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();

            if (!$wallet) {
                $wallet = $this->getOrCreate($userId);
                $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
            }

            $newBalance = round((float) $wallet->balance + $amount, 2);
            $wallet->update(['balance' => $newBalance]);

            WalletLedgerEntry::create([
                'wallet_id' => $wallet->id,
                'transaction_id' => $transactionId,
                'offline_qr_code_id' => $offlineQrCodeId,
                'payment_link_id' => $paymentLinkId,
                'type' => 'credit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'description' => $description,
                'created_at' => now(),
            ]);

            return $wallet;
        });
    }

    /**
     * Historique paginé des mouvements du wallet.
     */
    public function history(string $userId, int $perPage = 20)
    {
        $wallet = $this->getOrCreate($userId);

        return WalletLedgerEntry::where('wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Mouvement NET (débits - crédits) enregistré dans le LEDGER pour une
     * transaction donnée, sur le wallet donné.
     *
     * Source de vérité pour tout remboursement : on ne rembourse jamais un
     * montant recalculé à partir de la transaction (total_debited), mais
     * l'exact inverse du mouvement réellement enregistré. Conséquences :
     * - aucun débit au ledger  => net = 0 => aucun remboursement (on ne
     *   crée jamais d'argent) ;
     * - remboursement déjà effectué => net = 0 => idempotent (pas de double
     *   remboursement).
     */
    public function netMovementForTransaction(string $walletId, string $transactionId): float
    {
        $row = WalletLedgerEntry::query()
            ->where('wallet_id', $walletId)
            ->where('transaction_id', $transactionId)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN amount WHEN type = 'credit' THEN -amount ELSE 0 END), 0) AS net")
            ->first();

        return round((float) ($row?->net ?? 0), 2);
    }

    /**
     * Mouvement NET enregistré au ledger pour un QR « argent » donné
     * (colonne offline_qr_code_id) : hold à la génération (débit),
     * encaissement (crédit), remboursements (crédits).
     *
     * - QR généré (hold seul)      => net = montant détenu ;
     * - QR encaissé                => hold + crédit se neutralisent => 0 ;
     * - QR remboursé (revoke/annulation externe) => net = 0 => tout nouvel
     *   appel de remboursement est un no-op (idempotence, jamais de
     *   création d'argent).
     */
    public function netMovementForQr(int $offlineQrCodeId): float
    {
        $row = WalletLedgerEntry::query()
            ->where('offline_qr_code_id', $offlineQrCodeId)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN amount WHEN type = 'credit' THEN -amount ELSE 0 END), 0) AS net")
            ->first();

        return round((float) ($row?->net ?? 0), 2);
    }
}
