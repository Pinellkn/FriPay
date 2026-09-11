<?php

namespace App\Console\Commands;

use App\Models\OfflineQrCode;
use App\Models\OfflineQrEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Commande de réconciliation des QR Codes hors-ligne.
 *
 * Détecte les tentatives de double dépense en comparant les
 * réceptions locales avec le registre serveur.
 *
 * Stratégie de détection :
 * 1. QR codes avec plusieurs 'received' events de users différents
 * 2. QR codes avec plusieurs 'redeemed' events de users différents
 * 3. QR codes dont le statut est incohérent avec les events
 * 4. Suppression des QR codes expirés depuis plus de 30 jours
 *
 * Usage: php artisan reconcile:offline-qr [--dry-run]
 */
class ReconcileOfflineQr extends Command
{
    protected $signature = 'reconcile:offline-qr {--dry-run : Simuler sans modifier la base}';
    protected $description = 'Réconcilie les QR Codes hors-ligne et détecte les doubles dépenses';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('=== Réconciliation des QR Codes hors-ligne ===');
        $this->newLine();

        $doubleCount = 0;

        // ──────────────────────────────────────────────────
        // 1. Détection via la table d'événements
        //    (plusieurs 'received' par users différents = double réception)
        // ──────────────────────────────────────────────────
        $this->info('Détection via les événements...');

        $doublesByEvents = DB::select("
            SELECT offline_qr_code_id,
                   COUNT(DISTINCT actor_user_id) AS distinct_users,
                   COUNT(*) AS total_events
            FROM offline_qr_events
            WHERE event_type = 'received'
            GROUP BY offline_qr_code_id
            HAVING COUNT(DISTINCT actor_user_id) > 1
        ");

        $doublesByEvents = collect($doublesByEvents);

        if ($doublesByEvents->isNotEmpty()) {
            $this->error("  {$doublesByEvents->count()} QR Code(s) avec réceptions multiples :");
            $this->newLine();

            foreach ($doublesByEvents as $double) {
                $qrCode = OfflineQrCode::where('id', $double->offline_qr_code_id)->first();
                if (! $qrCode) {
                    continue;
                }

                $this->error("  UUID: {$qrCode->uuid} — {$double->distinct_users} users différents, {$double->total_events} events");

                // Lister les acteurs impliqués
                $actors = DB::table('offline_qr_events')
                    ->where('offline_qr_code_id', $double->offline_qr_code_id)
                    ->where('event_type', 'received')
                    ->distinct()
                    ->pluck('actor_user_id');

                $this->warn("    Acteurs: " . implode(', ', $actors->toArray()));

                if (! $dryRun) {
                    $this->flagAndRevoke($qrCode, (int) $double->distinct_users, 'double_reception');
                    $doubleCount++;
                } else {
                    $this->line("    [DRY-RUN] Serait révoqué");
                }
            }
        } else {
            $this->info('  Aucune double réception détectée via les événements.');
        }

        $this->newLine();

        // ──────────────────────────────────────────────────
        // 2. Détection via la table d'événements
        //    (plusieurs 'redeemed' par users différents = double encaissement)
        // ──────────────────────────────────────────────────
        $this->info('Détection des doubles encaissements...');

        $doublesByRedeem = DB::select("
            SELECT offline_qr_code_id,
                   COUNT(DISTINCT actor_user_id) AS distinct_users,
                   COUNT(*) AS total_events
            FROM offline_qr_events
            WHERE event_type = 'redeemed'
            GROUP BY offline_qr_code_id
            HAVING COUNT(DISTINCT actor_user_id) > 1
        ");

        $doublesByRedeem = collect($doublesByRedeem);

        if ($doublesByRedeem->isNotEmpty()) {
            $this->error("  {$doublesByRedeem->count()} QR Code(s) avec encaissements multiples :");
            $this->newLine();

            foreach ($doublesByRedeem as $double) {
                $qrCode = OfflineQrCode::where('id', $double->offline_qr_code_id)->first();
                if (! $qrCode) {
                    continue;
                }

                $this->error("  UUID: {$qrCode->uuid} — {$double->distinct_users} users différents");

                if (! $dryRun) {
                    $this->flagAndRevoke($qrCode, (int) $double->distinct_users, 'double_redeem');
                    $doubleCount++;
                } else {
                    $this->line("    [DRY-RUN] Serait révoqué");
                }
            }
        } else {
            $this->info('  Aucun double encaissement détecté.');
        }

        $this->newLine();

        // ──────────────────────────────────────────────────
        // 3. QR codes dont le statut est incohérent
        //    (statut 'received' mais event 'redeemed' existe)
        // ──────────────────────────────────────────────────
        $this->info('Detection des incohérences de statut...');

        $inconsistent = OfflineQrCode::where('status', 'received')
            ->whereHas('events', function ($q) {
                $q->where('event_type', 'redeemed');
            })
            ->get();

        if ($inconsistent->isNotEmpty()) {
            $this->error("  {$inconsistent->count()} QR Code(s) 'received' mais avec un event 'redeemed' :");
            foreach ($inconsistent as $qr) {
                $this->error("  UUID: {$qr->uuid} — statut: {$qr->status}");
                if (! $dryRun) {
                    $this->flagAndRevoke($qr, 2, 'status_inconsistency');
                    $doubleCount++;
                }
            }
        } else {
            $this->info('  Aucune incohérence de statut détectée.');
        }

        $this->newLine();

        // ──────────────────────────────────────────────────
        // 4. Expirer les QR Codes dépassés
        // ──────────────────────────────────────────────────
        $expiredCount = OfflineQrCode::where('status', OfflineQrCode::STATUS_ACTIVE)
            ->where('expires_at', '<', now())
            ->update(['status' => OfflineQrCode::STATUS_EXPIRED]);

        $this->info("{$expiredCount} QR Code(s) expiré(s).");

        $this->newLine();

        // ──────────────────────────────────────────────────
        // 5. Purge des QR Codes expirés depuis plus de 30 jours
        // ──────────────────────────────────────────────────
        $this->info('Purge des QR Codes expirés anciens (> 30 jours)...');

        $purgeThreshold = now()->subDays(30);

        // Supprimer d'abord les events associés
        $expiredQrIds = OfflineQrCode::where('status', OfflineQrCode::STATUS_EXPIRED)
            ->where('expires_at', '<', $purgeThreshold)
            ->pluck('id');

        if ($expiredQrIds->isNotEmpty()) {
            $purgeCount = $expiredQrIds->count();

            if (! $dryRun) {
                DB::table('offline_qr_events')
                    ->whereIn('offline_qr_code_id', $expiredQrIds)
                    ->delete();

                OfflineQrCode::whereIn('id', $expiredQrIds)->delete();

                $this->info("  {$purgeCount} QR Code(s) expiré(s) et événements associés supprimés.");
            } else {
                $this->line("  [DRY-RUN] {$purgeCount} QR Code(s) expiré(s) seraient supprimés.");
            }
        } else {
            $this->info('  Aucun QR Code expiré ancien à purger.');
        }

        $this->newLine();

        // ──────────────────────────────────────────────────
        // 6. Statistiques
        // ──────────────────────────────────────────────────
        $this->info('=== Statistiques ===');
        $stats = DB::table('offline_qr_codes')
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->groupBy('status')
            ->get();

        $table = [];
        foreach ($stats as $row) {
            $table[] = [
                'Status' => $row->status,
                'Nombre' => $row->count,
                'Total'  => number_format($row->total, 0, ',', ' ') . ' XOF',
            ];
        }
        $this->table(['Status', 'Nombre', 'Total'], $table);

        $this->newLine();

        if ($doubleCount > 0) {
            $this->error("{$doubleCount} anomalie(s) détectée(s) et traitée(s).");
        } else {
            $this->info('Réconciliation terminée — aucune anomalie.');
        }

        return self::SUCCESS;
    }

    /**
     * Marque un QR Code comme frauduleux et crée un event d'audit.
     */
    private function flagAndRevoke(OfflineQrCode $qrCode, int $userCount, string $reason): void
    {
        DB::transaction(function () use ($qrCode, $userCount, $reason) {
            OfflineQrEvent::create([
                'offline_qr_code_id' => $qrCode->id,
                'event_type'         => OfflineQrEvent::EVENT_RECONCILIATION_DOUBLE,
                'actor_user_id'      => null,
                'metadata'           => [
                    'reception_count' => $userCount,
                    'action'          => 'flagged_double_spend',
                    'reason'          => $reason,
                ],
            ]);

            $qrCode->update(['status' => OfflineQrCode::STATUS_REVOKED]);

            $this->warn("  QR Code révoqué: {$qrCode->uuid} (raison: {$reason})");
        });
    }
}
