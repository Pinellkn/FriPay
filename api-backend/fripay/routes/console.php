<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tâches planifiées
|--------------------------------------------------------------------------
| Traite les transferts en file d'attente (mode offline / connecteur
| indisponible) dès qu'un connecteur est disponible.
|
| En production : exécuter `php artisan schedule:run` toutes les minutes
| (cron / planificateur de tâches).
| En développement : le flush opportuniste déclenché par GET /transfers/...
| suffit, aucun worker ni cron n'est nécessaire.
|--------------------------------------------------------------------------
*/

Schedule::command('transfers:process-pending')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Purge des QR codes expirés + réconciliation hors-ligne (toutes les 6 heures)
Schedule::command('reconcile:offline-qr')
    ->everySixHours()
    ->withoutOverlapping()
    ->onOneServer();

/*
| Filets de sécurité paiements asynchrones (queues Redis + Horizon)
|--------------------------------------------------------------------------
| Les webhooks FeexPay peuvent être perdus (réseau, callback_url absente
| en dev) : ces deux tâches vérifient activement le statut auprès de
| l'agrégateur et complètent (crédit idempotent) ou expirent les entrées.
*/

// Recharges pending/processing : vérification FeexPay + crédit si confirmé.
Schedule::job(new \App\Jobs\RefreshPendingTopups)
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// FriPay Links en attente : vérification + passage automatique en `expired`.
Schedule::command('links:refresh-pending')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer();
