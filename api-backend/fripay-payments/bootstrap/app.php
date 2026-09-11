<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Override le middleware Auth pour API-only (jamais de redirect HTML)
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })
    ->withSchedule(function ($schedule): void {
        // Reconciliation des QR Codes hors-ligne : toutes les 5 minutes
        $schedule->command('reconcile:offline-qr')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // Traitement des transferts en file d'attente : toutes les minutes
        $schedule->command('transfers:process-pending')
            ->everyMinute()
            ->withoutOverlapping();
    })->create();
