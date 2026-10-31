<?php

use Illuminate\Support\Facades\Route;
use IslamGaber\SafeCheckout\Http\Controllers\WebhookController;

Route::post(trim((string) config('safe-checkout.route.prefix', 'checkout/webhooks'), '/').'/{gateway}', WebhookController::class)
    ->middleware(config('safe-checkout.route.middleware', []))
    ->where('gateway', '[A-Za-z0-9_\-]+')
    ->name('safe-checkout.webhook');
