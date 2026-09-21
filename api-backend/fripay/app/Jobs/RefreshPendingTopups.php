<?php

namespace App\Jobs;

use App\Models\Topup;
use App\Services\TopupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Vérification ACTIVE des recharges en attente (filet si le webhook
 * FeexPay n'arrive jamais).
 *
 * Ordonnancé toutes les 2 minutes (scheduler) : pour chaque topup
 * pending/processing portant une provider_reference, on interroge
 * FeexPay et on crédite si SUCCESSFUL (idempotent).
 */
class RefreshPendingTopups implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function handle(TopupService $topups): void
    {
        Topup::query()
            ->whereIn('status', ['pending', 'processing'])
            ->whereNotNull('provider_reference')
            ->where('updated_at', '>=', now()->subDay()) // pas les vieilles entrées
            ->orderBy('updated_at')
            ->limit(200) // lot borné : jamais d'explosion du temps du job
            ->get()
            ->each(fn (Topup $topup) => $topups->refreshStatus($topup));
    }

    public function backoff(): array
    {
        return [30];
    }
}
