<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook HMAC Secrets (agrégateurs externes)
    |--------------------------------------------------------------------------
    |
    | Clé partagée utilisée pour vérifier la signature HMAC (X-Signature)
    | des webhooks entrants. Chaque agrégateur a sa propre clé.
    |
    | Vérification : hash_hmac('sha256', $payload, $secret) === $signature
    |
    */
    'pispi' => [
        'webhook_secret' => env('PISPI_WEBHOOK_SECRET'),
    ],

    'kkiapay' => [
        'webhook_secret' => env('KKIAPAY_WEBHOOK_SECRET'),
    ],

    'fedapay' => [
        'webhook_secret' => env('FEDAPAY_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | MTN MoMo (API native — pas de signature HMAC)
    |--------------------------------------------------------------------------
    |
    | MTN n'envoie pas de signature sur ses callbacks. La seule vérification
    | possible est l'IP source. Laisser vide pour désactiver la vérification
    | (mode sandbox uniquement). En production, renseigner les IPs MTN.
    |
    */
    'mtn' => [
        'base_url' => env('MTN_MOMO_BASE_URL', 'https://sandbox.momodeveloper.mtn.com'),
        'api_user' => env('MTN_MOMO_API_USER'),
        'api_key' => env('MTN_MOMO_API_KEY'),
        'subscription_key' => env('MTN_MOMO_SUBSCRIPTION_KEY'),
        'target_environment' => env('MTN_MOMO_TARGET_ENVIRONMENT', 'sandbox'),
        'callback_url' => env('MTN_MOMO_CALLBACK_URL'),
        'allowed_ips' => array_filter(explode(',', env('MTN_MOMO_ALLOWED_IPS', ''))),
    ],

];
