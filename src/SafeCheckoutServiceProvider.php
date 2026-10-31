<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout;

use Illuminate\Support\ServiceProvider;
use IslamGaber\SafeCheckout\Console\ReconcilePendingCommand;

final class SafeCheckoutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/safe-checkout.php', 'safe-checkout');

        $this->app->singleton(CheckoutManager::class, fn ($app) => new CheckoutManager($app));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('safe-checkout.route.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/safe-checkout.php' => config_path('safe-checkout.php'),
            ], 'safe-checkout-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'safe-checkout-migrations');

            $this->commands([ReconcilePendingCommand::class]);
        }
    }
}
