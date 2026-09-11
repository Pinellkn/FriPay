<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::find('01a0869e-c67f-70e4-b720-3f5941bcfb0a');
if (!$user) { echo "USER NOT FOUND\n"; exit(1); }
$token = $user->createToken('debug-token')->plainTextToken;
echo $token . "\n";
