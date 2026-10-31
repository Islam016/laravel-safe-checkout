<?php

return [

    // Gateway used when Checkout::start() is called without a 'gateway' option.
    'default' => env('CHECKOUT_GATEWAY', 'moyasar'),

    'gateways' => [

        'moyasar' => [
            'driver' => 'moyasar',
            'secret_key' => env('MOYASAR_SECRET_KEY'),
            // The secret token you set for the webhook in the Moyasar dashboard.
            'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
            'base_url' => env('MOYASAR_BASE_URL', 'https://api.moyasar.com/v1'),
        ],

        'tap' => [
            'driver' => 'tap',
            'secret_key' => env('TAP_SECRET_KEY'),
            'base_url' => env('TAP_BASE_URL', 'https://api.tap.company/v2'),
            // Tap source: "src_all" shows every method enabled on your account.
            'source' => env('TAP_SOURCE', 'src_all'),
        ],

    ],

    'currency' => env('CHECKOUT_CURRENCY', 'SAR'),

    // Where the customer lands after the hosted payment page (can be overridden per payment).
    'success_url' => env('CHECKOUT_SUCCESS_URL'),
    'back_url' => env('CHECKOUT_BACK_URL'),

    // Webhooks are received at POST /{prefix}/{gateway}, e.g. /checkout/webhooks/moyasar.
    'route' => [
        'enabled' => true,
        'prefix' => 'checkout/webhooks',
        'middleware' => [],
    ],

    // Reconcile in a queued job instead of during the webhook request.
    'queue' => [
        'enabled' => env('CHECKOUT_QUEUE', false),
        'connection' => env('CHECKOUT_QUEUE_CONNECTION'),
        'name' => env('CHECKOUT_QUEUE_NAME'),
    ],

    'http' => [
        'timeout' => 15,
        'retries' => 2,
    ],

    'tables' => [
        'payments' => 'checkout_payments',
        'webhooks' => 'checkout_webhook_events',
    ],

];
