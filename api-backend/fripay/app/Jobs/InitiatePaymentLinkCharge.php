<?php

namespace App\Jobs;

use App\Models\PaymentLink;
use App\Services\PaymentLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Appel sortant FeexPay (requesttopay) pour un FriPay Link — DÉCOUPLÉ du
 * cycle requête HTTP.
 *
 * Le contrôleur public /payment-links/{token}/pay valide la requête puis
 * répond 202 immédiatement ; CE job appelle FeexPay (jusqu'à 30 s) et
 * mémorise la provider_reference sur le lien. En cas d'indisponibilité
 * FeexPay : retries automatiques avec backoff exponentiel.
 */
class InitiatePaymentLinkCharge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(
        public readonly string $linkId,
        public readonly string $payerPhone,   // MSISDN normalisé (+229…)
        public readonly string $operator,     // MTN | MOOV
    ) {
        $this->onQueue('feexpay');
    }

    public function handle(PaymentLinkService $links): void
    {
        $link = PaymentLink::find($this->linkId);

        // Lien supprimé ou devenu non-payable (payé/annulé/expiré) entre
        // l'acceptation et l'exécution : ne pas déclencher de collecte.
        if (! $link || ! $link->isPayable()) {
            return;
        }

        $result = $links->submitToProvider($link, $this->payerPhone, $this->operator);

        if (! ($result['success'] ?? false)) {
            if ($result['retryable'] ?? false) {
                throw new \RuntimeException('FeexPay injoignable : ' . ($result['message'] ?? '?'));
            }

            Log::warning('FriPay Link : collecte rejetée par FeexPay', [
                'link'   => $this->linkId,
                'reason' => $result['message'] ?? '?',
            ]);
            // Pas de changement d'état du lien : il reste payable, le payeur
            // peut retenter (rejet = erreur de la demande, pas du lien).
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Job InitiatePaymentLinkCharge échoué définitivement', [
            'link'  => $this->linkId,
            'error' => $e->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [30, 60, 120, 240];
    }
}
