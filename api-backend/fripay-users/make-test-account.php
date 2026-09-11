<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\LinkedAccount;

$user = User::first();
$account = LinkedAccount::where('user_id', $user->id)->first();

if (!$account) {
    $account = LinkedAccount::create([
        'user_id' => $user->id,
        'operator_id' => 1, // MTN
        'msisdn' => $user->phone_number,
        'alias_type' => 'msisdn',
        'alias_value' => $user->phone_number,
        'is_primary' => true,
        'status' => 'active',
    ]);
    echo "ACCOUNT_CREATED:" . $account->id . PHP_EOL;
} else {
    echo "ACCOUNT_FOUND:" . $account->id . PHP_EOL;
}
