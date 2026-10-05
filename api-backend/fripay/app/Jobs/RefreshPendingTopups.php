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

        // Filet : recharges pending/processing SANS provider_reference et
        // jamais soumises avec succès — la demande de collecte a été rejetée
        // (montant, réseau, 401, solde marchand…) ou le job d'initiation a
        // épuisé ses retries sans tracer l'échec. Passé 2 h, ces entrées ne
        // deviendront jamais des collectes réelles : on les échoue proprement
        // pour que l'app affiche « Recharge échouée » au lieu de « en attente »
        // à l'infini. Idempotent : on ne vise que sans provider_reference.
        Topup::query()
            ->whereIn('status', ['pending', 'processing'])
            ->whereNull('provider_reference')
            ->where('created_at', '<=', now()->subHours(2))
            ->limit(200)
            ->get()
            ->each(function (Topup $topup) {
                $topup->update([
                    'status'         => 'failed',
                    'failure_reason' => $topup->failure_reason
                        ?? "Collecte jamais soumise à FeexPay (aucune référence) — abandonnée après 2 h",
                ]);
            });
    }

    public function backoff(): array
    {
        return [30];
    }
}
