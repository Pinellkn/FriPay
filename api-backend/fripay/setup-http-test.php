<?php

// Provision + tokens pour le test HTTP de pay-wallet (nettoyés ensuite).
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Services\WalletService;
use Illuminate\Support\Facades\Hash;

$payer = User::where('phone_number', '+2290197000101')->first();
$creator = User::where('phone_number', '+2290167125906')->first();

$payer->forceFill(['pin_hash' => Hash::make('1234')])->save();
app(WalletService::class)->credit($payer->id, 2000, null, 'test_provision', 'provision http test');

echo $payer->createToken('t1')->plainTextToken . '|' . $creator->createToken('t2')->plainTextToken . PHP_EOL;
