<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\TransactionStatusHistory;
use App\Services\TransferService;
use App\Services\Connectors\FeexpayPayoutConnector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Vérification ACTIVE des payouts FeexPay en attente (filet si le webhook
 * FeexPay n'arrive jamais — cas du dev local, non joignable par FeexPay).
 *
 * Ordonnancé toutes les 2 minutes (scheduler, cf. routes/console.php) :
 * pour chaque transaction processing dont external_reference est une
 * référence FeexPay, on interroge « Statut des paiements » et on
 * finalise : succeeded (argent livré) ou failed (+ remboursement du
 * wallet, via TransferService::refundWallet — source de vérité ledger).
 *
 * Idempotent : la requête ne vise que statut = processing, donc une
 * transaction déjà finalisée n'est jamais re-traitée.
 */
class RefreshPendingPayouts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(private readonly FeexpayPayoutConnector $feexpayPayouts = new FeexpayPayoutConnector())
    {
    }

    public function handle(TransferService $transfers): void
    {
        $transactions = Transaction::query()
            ->where('status', 'processing')
            ->where('rail_used', 'aggregator')
            ->whereNotNull('external_reference')
            ->where('updated_at', '>=', now()->subDay()) // pas les vieilles entrées
            ->orderBy('updated_at')
            ->limit(200) // lot borné : jamais d'explosion du temps du job
            ->get();

        foreach ($transactions as $transaction) {
            $status = $this->feexpayPayouts->checkPayoutStatus($transaction->external_reference);

            $finalStatus = match ($status['status'] ?? null) {
                'SUCCESSFUL' => 'succeeded',
                'FAILED'     => 'failed',
                default      => null, // PENDING / inconnu / indisponible : on retentera
            };

            if ($finalStatus === null) {
                continue;
            }

            $previous = $transaction->status;

            $transaction->update([
                'status'       => $finalStatus,
                'completed_at' => now(),
            ]);

            TransactionStatusHistory::create([
                'transaction_id'   => $transaction->id,
                'previous_status'  => $previous,
                'new_status'       => $finalStatus,
                'source'           => 'polling',
                'note'             => 'Payout FeexPay confirmé par vérification active',
            ]);

            if ($finalStatus === 'failed') {
                // Remboursement idempotent (source de vérité = ledger).
                $transfers->refundWallet($transaction, 'transfer_refund_payout_failed');
            }

            Log::info('Payout FeexPay confirmé par vérification active', [
                'transaction' => $transaction->id,
                'reference'   => $transaction->external_reference,
                'de'          => $previous,
                'vers'        => $finalStatus,
            ]);
        }
    }

    public function backoff(): array
    {
        return [30];
    }
}
