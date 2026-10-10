<?php

namespace App\Jobs;

use App\Services\PaymentLinkService;
use App\Models\WebhookEvent;
use App\Services\TopupService;
use App\Services\TransferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Traitement DÉCOUPLÉ du webhook FeexPay.
 *
 * Le contrôleur webhook journalise l'événement brut (WebhookEvent) et
 * répond 200 à FeexPay IMMÉDIATEMENT — jamais de retry en cascade côté
 * agrégateur parce que notre worker HTTP était occupé. La vérification
 * auprès de l'API FeexPay (source de vérité) puis le crédit éventuel se
 * font ici, dans le worker de queue.
 *
 * Idempotent : markLinkPaid/refreshStatus ne créditent jamais deux fois.
 */
class ProcessFeexpayWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $webhookEventId,
        public readonly string $providerReference,
        // true si l'appel porte le secret de webhook (FEEXPAY_WEBHOOK_SECRET).
        // Requis pour finaliser un PAYOUT : aucune API de statut payout ne
        // permet de recouper, donc un faux FAILED forgé déclencherait un
        // remboursement indu.
        public readonly bool $trusted = false,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(TopupService $topups, PaymentLinkService $links): void
    {
        // 1) Recharge (topup) ? La référence FeexPay permet de la retrouver.
        $topup = $topups->refreshStatusByProviderReference($this->providerReference);

        if ($topup) {
            Log::info('Webhook FeexPay traité (topup)', [
                'reference' => $this->providerReference,
                'topup'     => $topup->id,
                'status'    => $topup->status,
            ]);
            return;
        }

        // 2) Sinon : paiement d'un FriPay Link ? (même vérification API,
        //    source de vérité — un callback forgé ne crédite rien).
        $link = $links->confirmByProviderReference($this->providerReference);

        if ($link) {
            Log::info('Webhook FeexPay traité (FriPay Link)', [
                'reference' => $this->providerReference,
                'link'      => $link->id,
                'status'    => $link->status,
            ]);
            return;
        }

        // 3) Sinon : payout (FriPay -> MTN/Moov/Celtiis) ? Finalisation
        //    verrouillée et idempotente via TransferService::finalize().
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event && $this->handlePayout($event)) {
            return;
        }

        // Référence inconnue des trois systèmes : journaliser pour audit.
        // Réponse 200 quand même (déjà renvoyée au provider).
        Log::warning('Webhook FeexPay : référence inconnue', [
            'reference' => $this->providerReference,
        ]);
    }

    private function handlePayout(WebhookEvent $event): bool
    {
        if (! $this->trusted) {
            $event->update(['processing_error' => 'Webhook payout ignoré : secret absent ou invalide']);

            Log::warning('Webhook FeexPay payout ignoré (non authentifié)', [
                'reference' => $this->providerReference,
                'event'     => $event->id,
            ]);

            return false;
        }

        $payout = app(TransferService::class)->applyFeexpayPayoutWebhook($event->payload ?? [], $this->providerReference);

        if (! $payout) {
            return false;
        }

        $event->update(['processed' => true]);

        Log::info('Webhook FeexPay traité (payout)', [
            'reference'   => $this->providerReference,
            'transaction' => $payout->id,
            'status'      => $payout->status,
        ]);

        return true;
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Job ProcessFeexpayWebhook échoué définitivement', [
            'webhook_event' => $this->webhookEventId,
            'reference'     => $this->providerReference,
            'error'         => $e->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [10, 30];
    }
}
