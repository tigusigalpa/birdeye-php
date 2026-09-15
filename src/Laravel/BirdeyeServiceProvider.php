<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye\Laravel;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Tigusigalpa\Birdeye\Client;

/**
 * Laravel 10-13 integration: binds a Client singleton from
 * config/birdeye.php and publishes that config file. The core Client
 * has no dependency on Laravel or on any specific HTTP client — this
 * provider only wires up a default PSR-18/PSR-17 binding (Guzzle, if
 * installed) for convenience.
 *
 * A worker process (queue worker, Octane) reuses this container binding
 * across jobs/requests by default — safe here, since every call is a
 * stateless REST request.
 */
class BirdeyeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/birdeye.php', 'birdeye');

        if (!$this->app->bound(ClientInterface::class) && class_exists(GuzzleClient::class)) {
            $this->app->singleton(ClientInterface::class, fn () => new GuzzleClient());
        }
        if ((!$this->app->bound(RequestFactoryInterface::class) || !$this->app->bound(StreamFactoryInterface::class)) && class_exists(HttpFactory::class)) {
            $this->app->singleton(HttpFactory::class, fn () => new HttpFactory());
        }
        if (!$this->app->bound(RequestFactoryInterface::class) && class_exists(HttpFactory::class)) {
            $this->app->bind(RequestFactoryInterface::class, HttpFactory::class);
        }
        if (!$this->app->bound(StreamFactoryInterface::class) && class_exists(HttpFactory::class)) {
            $this->app->bind(StreamFactoryInterface::class, HttpFactory::class);
        }

        $this->app->singleton(Client::class, function ($app) {
            if (!$app->bound(ClientInterface::class) || !$app->bound(RequestFactoryInterface::class) || !$app->bound(StreamFactoryInterface::class)) {
                throw new \RuntimeException(
                    'Tigusigalpa\\Birdeye\\Client needs a PSR-18 HTTP client and PSR-17 factories bound. '
                    . 'Install guzzlehttp/guzzle + guzzlehttp/psr7 for the default binding, or bind your own '
                    . 'Psr\\Http\\Client\\ClientInterface / Psr\\Http\\Message\\RequestFactoryInterface / StreamFactoryInterface.'
                );
            }

            $defaultChain = (string) config('birdeye.default_chain', '');

            return new Client(
                httpClient: $app->make(ClientInterface::class),
                requestFactory: $app->make(RequestFactoryInterface::class),
                streamFactory: $app->make(StreamFactoryInterface::class),
                apiKey: (string) config('birdeye.api_key', ''),
                defaultChain: $defaultChain !== '' ? $defaultChain : null,
                baseUrl: (string) config('birdeye.base_url', Client::DEFAULT_BASE_URL),
            );
        });

        $this->app->alias(Client::class, 'birdeye');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/birdeye.php' => config_path('birdeye.php'),
            ], 'birdeye-config');
        }
    }
}
