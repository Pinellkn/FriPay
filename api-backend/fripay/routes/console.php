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
