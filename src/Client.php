<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tigusigalpa\Birdeye\Config\ClockInterface;
use Tigusigalpa\Birdeye\Config\RetryPolicy;
use Tigusigalpa\Birdeye\Config\SystemClock;
use Tigusigalpa\Birdeye\Http\RequestExecutor;
use Tigusigalpa\Birdeye\Price\Client as PriceClient;

/**
 * The SDK entry point. Has no dependency on any specific HTTP client
 * implementation or on Laravel — inject any PSR-18 ClientInterface and
 * PSR-17 factories. For a Laravel app, see
 * Laravel\BirdeyeServiceProvider, which binds a default using whatever
 * PSR-18 client is available.
 *
 * Docs: https://docs.birdeye.so/reference/birdeye-api-getting-started
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://public-api.birdeye.so';

    /**
     * Groups every implemented price/OHLCV endpoint.
     *
     * Docs: https://docs.birdeye.so/reference/price-ohlcv
     */
    public readonly PriceClient $price;

    private readonly RequestExecutor $executor;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        string $apiKey,
        ?string $defaultChain = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ClockInterface $clock = new SystemClock(),
        LoggerInterface $logger = new NullLogger(),
        RetryPolicy $retryPolicy = new RetryPolicy(),
    ) {
        $this->executor = new RequestExecutor(
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            defaultChain: $defaultChain,
            clock: $clock,
            logger: $logger,
            retryPolicy: $retryPolicy,
        );

        $this->price = new PriceClient($this->executor);
    }

    /**
     * The raw request escape hatch: call any Birdeye endpoint —
     * including ones this SDK hasn't mapped to a typed method yet —
     * without waiting for an SDK update. $chain overrides the client's
     * default x-chain header for this call only (null to use the
     * default).
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed> Decoded "data" payload.
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, ?string $chain = null): array
    {
        return $this->executor->request($method, $path, $query, $body, $chain);
    }

    /**
     * Raw-request escape hatch with additional endpoint-specific headers, such
     * as x-perp. The configured API key and the chain argument always win over
     * headers supplied here.
     *
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function requestWithHeaders(string $method, string $path, array $headers, array $query = [], ?array $body = null, ?string $chain = null): array
    {
        return $this->executor->requestWithHeaders($method, $path, $headers, $query, $body, $chain);
    }

    /**
     * The RequestExecutor::getLastResponseMeta() equivalent, exposed at
     * the client level for convenience.
     */
    public function getExecutor(): RequestExecutor
    {
        return $this->executor;
    }
}
