<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connecteurs de paiement mobile money
    |--------------------------------------------------------------------------
    |
    | Chaque reseau GSM (MTN, Moov, Celtiis) dispose d'une API native. Chaque
    | integration implemente App\Contracts\TransferConnector et est declaree
    | ici, soit par operateur (code), soit par fournisseur de corridor
    | (rail / aggregator_provider).
    |
    | Tant qu'aucun connecteur n'est enregistre, les transferts acceptes
    | restent en file d'attente (outbox) et sont executes des qu'un
    | connecteur devient disponible.
    |
    */
    'connectors' => [
        'MTN'     => \App\Services\Connectors\MtnMomoConnector::class,
        'MOOV'    => \App\Services\Connectors\MoovMoneyConnector::class,
        'CELTIIS' => \App\Services\Connectors\CeltiisConnector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Connecteur MTN MoMo (API native, produit Disbursements)
    |--------------------------------------------------------------------------
    |
    | Cles : https://momodeveloper.mtn.com (creer un API User + API Key)
    |
    */
    'mtn_momo' => [
        'api_user'           => env('MTN_MOMO_API_USER'),
        'api_key'            => env('MTN_MOMO_API_KEY'),
        'subscription_key'   => env('MTN_MOMO_SUBSCRIPTION_KEY'),
        'target_environment' => env('MTN_MOMO_TARGET_ENVIRONMENT', 'sandbox'),
        'base_url'           => env('MTN_MOMO_BASE_URL', 'https://sandbox.momodeveloper.mtn.com'),
        'callback_url'       => env('MTN_MOMO_CALLBACK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connecteur Moov Money (API SOAP, Moov Africa Benin)
    |--------------------------------------------------------------------------
    |
    | Cles : Marchand Moov Africa Benin (username, password, encryption_key)
    | API  : https://moov-africa.bj
    |
    */
    'moov_money' => [
        'username'           => env('MOOV_MONEY_USERNAME'),
        'password'           => env('MOOV_MONEY_PASSWORD'),
        'encryption_key'     => env('MOOV_MONEY_ENCRYPTION_KEY'),
        'target_environment' => env('MOOV_MONEY_TARGET_ENVIRONMENT', 'sandbox'),
        'base_url'           => env('MOOV_MONEY_BASE_URL', 'https://testapimarchand2.moov-africa.bj:2010/com.tlc.merchant.api/UssdPush?wsdl'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connecteur Celtiis Cash (via agrégateur PayDunya)
    |--------------------------------------------------------------------------
    |
    | Celtiis Cash n'a pas d'API publique. Ce connecteur utilise l'API
    | Disbursement de PayDunya (https://developers.paydunya.com).
    | Cles : PayDunya (master_key, private_key, token)
    |
    */
    'celtiis' => [
        'master_key'   => env('PAYDUNYA_MASTER_KEY'),
        'private_key'  => env('PAYDUNYA_PRIVATE_KEY'),
        'token'        => env('PAYDUNYA_TOKEN'),
        'base_url'     => env('PAYDUNYA_BASE_URL', 'https://app.paydunya.com'),
        'callback_url' => env('CELTIIS_CALLBACK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | File d'attente des transferts différés (outbox)
    |--------------------------------------------------------------------------
    |
    | Quand le connecteur du reseau est injoignable (reseau, timeout, HTTP
    | 5xx) ou non encore integre, le transfert est accepte en statut 'pending'
    | et mis en file d'attente locale. La commande `transfers:process-pending`
    | (ou le flush opportuniste declenche par GET /transfers/...) le traite
    | des que la connexion revient.
    |
    */
    'outbox' => [
        'enabled' => (bool) env('FRIPAY_OUTBOX_ENABLED', true),

        // Nombre maximal de tentatives avant echec definitif de la transaction.
        'max_attempts' => (int) env('FRIPAY_OUTBOX_MAX_ATTEMPTS', 10),

        // Backoff exponentiel : base (s) * 2^(tentative-1), plafonne a backoff_max.
        'backoff_base_seconds' => (int) env('FRIPAY_OUTBOX_BACKOFF_BASE', 60),
        'backoff_max_seconds'  => (int) env('FRIPAY_OUTBOX_BACKOFF_MAX', 86400),

        // Delai avant de re-tester un transfert dont le connecteur n'est pas
        // encore disponible (aucune tentative consommee dans ce cas).
        'no_connector_retry_seconds' => (int) env('FRIPAY_OUTBOX_NO_CONNECTOR_RETRY', 3600),

        // Nombre d'items traites par execution (commande ou flush opportuniste).
        'batch_size' => (int) env('FRIPAY_OUTBOX_BATCH_SIZE', 10),
    ],

];
