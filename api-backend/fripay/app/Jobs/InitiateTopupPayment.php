<?php

namespace App\Jobs;

use App\Models\Topup;
use App\Services\TopupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Appel sortant FeexPay (requesttopay) DÉCOUPLÉ du cycle requête HTTP.
 *
 * Le contrôleur crée le topup local (statut `pending`) et répond
 * immédiatement 202 au client ; c'est CE job, exécuté par un worker
 * Horizon, qui appelle l'API FeexPay (jusqu'à 30 s). Le worker HTTP ne
 * bloque plus : un burst de recharges ne peut plus épuiser les workers
 * web — c'était le goulot n°1 à haute charge (QUEUE_CONNECTION=sync).
 *
 * Si FeexPay est injoignable : retry automatique avec backoff
 * exponentiel (30 s, 60 s, 120 s…), puis échec tracé sur le topup.
 */
class InitiateTopupPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 5 tentatives (x backoff 30/60/120/240 s) avant échec définitif. */
    public int $tries = 5;

    /** Timeout du job — doit rester inférieur au retry_after de la queue. */
    public int $timeout = 60;

    public function __construct(
        public readonly string $topupId,
    ) {
        // File dédiée : les appels agrégateur ne concurrencent pas les
        // jobs rapides (notifications…), et peuvent être scalés séparément.
        $this->onQueue('feexpay');
    }

    public function handle(TopupService $topups): void
    {
        $topup = Topup::find($this->topupId);

        // Topup annulé/supprimé entre-temps : rien à faire.
        if (! $topup || ! in_array($topup->status, ['pending', 'processing'], true)) {
            return;
        }

        // Déjà soumis à FeexPay (retry après un crash post-appel) : ne pas
        // re-déclencher la collecte — la référence existante fait foi, le
        // webhook/refreshStatus complétera le topup.
        if ($topup->provider_reference) {
            return;
        }

        $result = $topups->submitToProvider($topup);

        if (! ($result['success'] ?? false)) {
            if ($result['retryable'] ?? false) {
                // Injoignable : le job est relancé (backoff), sans marquer
                // le topup en échec — c'est un problème d'indisponibilité.
                throw new \RuntimeException('FeexPay injoignable : ' . ($result['message'] ?? '?'));
            }

            // Rejet définitif (montant, opérateur, numéro invalide…) :
            // échec immédiat tracé, aucune relance inutile.
            $topup->update([
                'status'         => 'failed',
                'failure_reason' => $result['message'] ?? 'Rejeté par FeexPay',
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        // Échec définitif après tous les retries : tracer sur le topup.
        if ($topup = Topup::find($this->topupId)) {
            if (in_array($topup->status, ['pending', 'processing'], true) && ! $topup->provider_reference) {
                $topup->update([
                    'status'         => 'failed',
                    'failure_reason' => 'Collecte non soumise : ' . $e->getMessage(),
                ]);
            }
        }

        Log::error('Job InitiateTopupPayment échoué définitivement', [
            'topup' => $this->topupId,
            'error' => $e->getMessage(),
        ]);
    }

    /** Backoff exponentiel : 30 s, 60 s, 120 s, 240 s. */
    public function backoff(): array
    {
        return [30, 60, 120, 240];
    }
}
