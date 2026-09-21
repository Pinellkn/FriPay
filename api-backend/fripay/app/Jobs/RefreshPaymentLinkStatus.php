<?php

namespace App\Jobs;

use App\Models\PaymentLink;
use App\Services\PaymentLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Vérification ACTIVE du statut FeexPay d'un FriPay Link.
 *
 * Filet de sécurité si le webhook n'arrive jamais (réseau, callback_url
 * non configurée en dev…) : ce job interroge l'API FeexPay et confirme le
 * paiement si SUCCESSFUL (crédit ledger + notification idempotents).
 *
 * Ordonnancé par ExpireStalePaymentLinks / la commande links:refresh-pending
 * sur les liens `created` portant une provider_reference.
 */
class RefreshPaymentLinkStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $linkId,
    ) {
        $this->onQueue('feexpay');
    }

    public function handle(PaymentLinkService $links): void
    {
        $link = PaymentLink::find($this->linkId);

        if (! $link || $link->status !== PaymentLink::STATUS_CREATED) {
            return; // déjà payé/annulé/expiré : rien à vérifier
        }

        // Vérifie auprès de FeexPay et marque payé si SUCCESSFUL
        // (crédit + notification, idempotents).
        $links->refreshFromProvider($link);
    }

    public function backoff(): array
    {
        return [10, 30];
    }
}
