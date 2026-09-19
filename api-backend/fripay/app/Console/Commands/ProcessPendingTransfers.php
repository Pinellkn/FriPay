<?php

namespace App\Console\Commands;

use App\Services\TransferService;
use Illuminate\Console\Command;

class ProcessPendingTransfers extends Command
{
    protected $signature = 'transfers:process-pending {--batch= : Nombre de transferts à traiter maximum par exécution}';

    protected $description = 'Traite les transferts en file d\'attente (outbox) dès qu\'un connecteur est disponible';

    public function handle(TransferService $transferService): int
    {
        $result = $transferService->processPendingTransfers(
            $this->option('batch') !== null ? (int) $this->option('batch') : null
        );

        if (($result['skipped'] ?? false) === true) {
            $this->warn('Exécution ignorée : ' . ($result['reason'] ?? 'verrou déjà actif'));

            return self::SUCCESS;
        }

        $this->info("Transferts traités : {$result['processed']}");

        return self::SUCCESS;
    }
}
