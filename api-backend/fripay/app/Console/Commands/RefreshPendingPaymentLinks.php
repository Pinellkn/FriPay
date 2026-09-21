<?php

namespace App\Console\Commands;

use App\Jobs\RefreshPaymentLinkStatus;
use App\Models\PaymentLink;
use Illuminate\Console\Command;

/**
 * Filet de sécurité FriPay Link : pour les liens `created` portant une
 * provider_reference (collecte initiée), vérifie le statut auprès de
 * FeexPay au cas où le webhook ne serait jamais arrivé ; et marque
 * `expired` les liens dépassant leur date d'expiration.
 *
 * Ordonnancé toutes les 2 minutes (routes/console.php).
 */
class RefreshPendingPaymentLinks extends Command
{
    protected $signature = 'links:refresh-pending {--limit=200 : Nombre maximal de liens traités par exécution}';

    protected $description = 'Vérifie les paiements FeexPay en attente des FriPay Links et marque les liens expirés';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // 1) Liens avec collecte initiée : vérification active (queue feexpay).
        $pending = PaymentLink::query()
            ->where('status', PaymentLink::STATUS_CREATED)
            ->whereNotNull('provider_reference')
            ->where('created_at', '>=', now()->subDays(3)) // fenêtre raisonnable
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($pending as $linkId) {
            RefreshPaymentLinkStatus::dispatch($linkId);
        }

        // 2) Liens créés dont la date d'expiration est passée : marquer expiré.
        $expired = PaymentLink::query()
            ->where('status', PaymentLink::STATUS_CREATED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => PaymentLink::STATUS_EXPIRED]);

        $this->info("Liens vérifiés (dispatch) : {$pending->count()} — liens expirés : {$expired}");

        return self::SUCCESS;
    }
}
