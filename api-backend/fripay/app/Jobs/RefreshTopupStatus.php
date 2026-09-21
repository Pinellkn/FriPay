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
 * Vérification ACTIVE du statut d'un topup auprès de FeexPay.
 *
 * Déclenchée par le polling du client (GET /wallet/topup/feexpay/{id}) :
 * le contrôleur répond immédiatement avec l'état LOCAL du topup et
 * dispatche ce job — l'appel sortant getStatus (jusqu'à 20 s) part dans
 * la queue `feexpay`, jamais dans le cycle requête HTTP. Le résultat est
 * visible au poll suivant (quelques secondes plus tard).
 *
 * Idempotent : markCompleted ne crédite jamais deux fois.
 */
class RefreshTopupStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $topupId,
    ) {
        $this->onQueue('feexpay');
    }

    public function handle(TopupService $topups): void
    {
        $topup = Topup::find($this->topupId);

        if (! $topup) {
            return;
        }

        // Vérifie auprès de FeexPay et crédite si SUCCESSFUL (idempotent).
        $topups->refreshStatus($topup);
    }

    public function backoff(): array
    {
        return [10, 30];
    }
}
