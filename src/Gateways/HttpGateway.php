<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use IslamGaber\SafeCheckout\Contracts\Gateway;
use IslamGaber\SafeCheckout\Exceptions\GatewayRequestFailed;
use Throwable;

abstract class HttpGateway implements Gateway
{
    /** @param array<string, mixed> $config */
    public function __construct(protected readonly string $name, protected readonly array $config)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    abstract protected function authenticate(PendingRequest $request): PendingRequest;

    abstract protected function defaultBaseUrl(): string;

    protected function request(): PendingRequest
    {
        return $this->authenticate(
            Http::baseUrl(rtrim((string) ($this->config['base_url'] ?? $this->defaultBaseUrl()), '/'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('safe-checkout.http.timeout', 15))
        );
    }

    /**
     * GET is safe to retry on connection errors and 5xx responses.
     * POST is never retried here: a retried "create" could open a second checkout.
     */
    protected function get(string $path, string $action): Response
    {
        try {
            $response = $this->request()
                ->retry(
                    (int) config('safe-checkout.http.retries', 2) + 1,
                    200,
                    fn (Throwable $e): bool => $e instanceof ConnectionException
                        || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->get($path);
        } catch (ConnectionException $e) {
            throw new GatewayRequestFailed("{$this->name} {$action} failed: {$e->getMessage()}", previous: $e);
        }

        return $this->ensureSuccessful($response, $action);
    }

    /** @param array<string, mixed> $payload */
    protected function post(string $path, array $payload, string $action): Response
    {
        try {
            $response = $this->request()->post($path, $payload);
        } catch (ConnectionException $e) {
            throw new GatewayRequestFailed("{$this->name} {$action} failed: {$e->getMessage()}", previous: $e);
        }

        return $this->ensureSuccessful($response, $action);
    }

    protected function ensureSuccessful(Response $response, string $action): Response
    {
        if (! $response->successful()) {
            throw GatewayRequestFailed::fromResponse($this->name, $action, $response);
        }

        return $response;
    }

    protected function secretKey(): string
    {
        $key = (string) ($this->config['secret_key'] ?? '');

        if ($key === '') {
            throw new InvalidArgumentException("Set the secret key for the [{$this->name}] gateway.");
        }

        return $key;
    }

    /** @param array<string, mixed> $options */
    protected function successUrl(array $options): ?string
    {
        return $options['success_url'] ?? config('safe-checkout.success_url');
    }
}
