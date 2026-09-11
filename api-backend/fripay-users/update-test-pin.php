<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

$user = User::first();
$user->pin_hash = bcrypt('12345');
$user->save();
echo "PIN_UPDATED_FOR:" . $user->id . PHP_EOL;
