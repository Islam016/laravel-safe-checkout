<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IslamGaber\SafeCheckout\SafeCheckoutServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const MOYASAR_KEY = 'sk_test_moyasar';
    public const MOYASAR_WEBHOOK_SECRET = 'whsec_moyasar';
    public const TAP_KEY = 'sk_test_tap';

    /** @var list<array{method: string, url: string, response: callable|array}> */
    protected array $stubs = [];

    protected function getPackageProviders($app): array
    {
        return [SafeCheckoutServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', match ($driver) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'testing',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '5432',
                'database' => getenv('DB_DATABASE') ?: 'testing',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
            ],
            default => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        });

        $app['config']->set('safe-checkout.gateways.moyasar.secret_key', self::MOYASAR_KEY);
        $app['config']->set('safe-checkout.gateways.moyasar.webhook_secret', self::MOYASAR_WEBHOOK_SECRET);
        $app['config']->set('safe-checkout.gateways.tap.secret_key', self::TAP_KEY);
        $app['config']->set('safe-checkout.success_url', 'https://shop.test/thank-you');
        $app['config']->set('safe-checkout.http.retries', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh')->run();

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            foreach (array_reverse($this->stubs) as $stub) {
                if ($stub['method'] === $request->method() && $stub['url'] === $request->url()) {
                    $response = is_callable($stub['response']) ? ($stub['response'])($request) : $stub['response'];

                    return isset($response['__status'])
                        ? Http::response($response['__body'] ?? [], $response['__status'])
                        : Http::response($response, 200);
                }
            }

            return Http::response(['message' => 'No stub for '.$request->method().' '.$request->url()], 599);
        });
    }

    /**
     * Register a fake gateway response. Later stubs win over earlier ones for the same URL.
     * Use ['__status' => 500, '__body' => [...]] for an error response.
     */
    protected function stub(string $method, string $url, callable|array $response): void
    {
        $this->stubs[] = ['method' => $method, 'url' => $url, 'response' => $response];
    }

    /** @return array<string, mixed> */
    protected function moyasarInvoice(string $id, string $status, int $amount, string $currency = 'SAR'): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'description' => 'Order',
            'url' => "https://checkout.moyasar.com/invoices/{$id}",
            'payments' => [],
        ];
    }

    /** @return array<string, mixed> */
    protected function moyasarWebhook(string $eventId, string $invoiceId, string $secret = self::MOYASAR_WEBHOOK_SECRET): array
    {
        return [
            'id' => $eventId,
            'type' => 'payment_paid',
            'created_at' => '2026-10-31T09:00:00.000Z',
            'secret_token' => $secret,
            'account_name' => 'Test',
            'live' => false,
            'data' => [
                'id' => 'pay_'.$eventId,
                'status' => 'paid',
                'amount' => 15075,
                'currency' => 'SAR',
                'invoice_id' => $invoiceId,
            ],
        ];
    }
}
