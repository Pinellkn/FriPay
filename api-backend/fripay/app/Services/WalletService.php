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
    public function debit(string $userId, float $amount, ?string $transactionId, string $reason, ?string $description = null): Wallet
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('AMOUNT_MUST_BE_POSITIVE');
        }

        return DB::transaction(function () use ($userId, $amount, $transactionId, $reason, $description) {
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
    public function credit(string $userId, float $amount, ?string $transactionId, string $reason, ?string $description = null): Wallet
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('AMOUNT_MUST_BE_POSITIVE');
        }

        return DB::transaction(function () use ($userId, $amount, $transactionId, $reason, $description) {
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
}
