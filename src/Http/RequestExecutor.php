<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tigusigalpa\Birdeye\Config\ClockInterface;
use Tigusigalpa\Birdeye\Config\RetryPolicy;
use Tigusigalpa\Birdeye\Config\SystemClock;
use Tigusigalpa\Birdeye\Exception\AuthenticationException;
use Tigusigalpa\Birdeye\Exception\BirdeyeException;
use Tigusigalpa\Birdeye\Exception\ForbiddenException;
use Tigusigalpa\Birdeye\Exception\InvalidRequestException;
use Tigusigalpa\Birdeye\Exception\NotFoundException;
use Tigusigalpa\Birdeye\Exception\RateLimitException;
use Tigusigalpa\Birdeye\Exception\ServerException;

/**
 * The shared, PSR-18/PSR-17-based HTTP transport for the Birdeye REST
 * API. Attaches X-API-KEY/x-chain headers, decodes Birdeye's
 * {success, data, message} response envelope, captures response
 * metadata, and retries idempotent (GET) calls per its RetryPolicy,
 * honoring a Retry-After response header when present.
 *
 * Does not hardcode Guzzle or any other HTTP client — inject any PSR-18
 * ClientInterface implementation.
 */
final class RequestExecutor
{
    private ?ResponseMeta $lastResponseMeta = null;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly ?string $defaultChain = null,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
    ) {
    }

    /**
     * The ResponseMeta (HTTP status, envelope success/message, raw body,
     * headers) from the most recent call on this executor.
     */
    public function getLastResponseMeta(): ?ResponseMeta
    {
        return $this->lastResponseMeta;
    }

    /**
     * Issues a request and returns the decoded "data" payload. $chain
     * overrides the client's default x-chain header for this call only
     * (null to use the default).
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed> Decoded "data" payload; an empty
     *   array if Birdeye returned data:null.
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, ?string $chain = null): array
    {
        $data = $this->doRequest($method, $path, $query, $body, $chain);

        return is_array($data) ? $data : [];
    }

    private function doRequest(string $method, string $path, array $query, ?array $body, ?string $chain): mixed
    {
        $endpoint = $path;
        $filtered = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        if ($filtered !== []) {
            ksort($filtered);
            $endpoint .= '?' . http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);
        }

        $bodyJson = $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : '';
        $chain ??= $this->defaultChain;

        $retryable = strtoupper($method) === 'GET';
        $maxAttempts = ($retryable && $this->retryPolicy->maxAttempts > 1) ? $this->retryPolicy->maxAttempts : 1;

        $startMs = $this->clock->nowMillis();
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                $delayMs = $this->delayForAttempt($attempt - 1, $lastException);
                if ($this->retryPolicy->maxElapsedMs > 0 && ($this->clock->nowMillis() - $startMs + $delayMs) > $this->retryPolicy->maxElapsedMs) {
                    break;
                }
                if ($this->retryPolicy->onRetry !== null) {
                    ($this->retryPolicy->onRetry)($attempt - 1, $delayMs, $lastException);
                }
                usleep($delayMs * 1000);
            }

            try {
                return $this->attempt($method, $endpoint, $bodyJson, $chain, $attempt);
            } catch (BirdeyeException|ClientExceptionInterface $e) {
                $lastException = $e;
                if (!$retryable || !$this->isRetryable($e)) {
                    throw $e;
                }
            }
        }

        throw $lastException;
    }

    /**
     * Prefers a Retry-After header from the previous response (seconds
     * or an HTTP-date, per RFC 7231) over the policy's own
     * backoff/jitter calculation.
     */
    private function delayForAttempt(int $attempt, ?\Throwable $lastException): int
    {
        if ($lastException instanceof RateLimitException) {
            $retryAfter = null;
            foreach ($lastException->headers ?? [] as $name => $values) {
                if (strtolower($name) === 'retry-after' && $values !== []) {
                    $retryAfter = $values[0];
                    break;
                }
            }
            if ($retryAfter !== null) {
                if (ctype_digit($retryAfter)) {
                    return ((int) $retryAfter) * 1000;
                }
                $when = strtotime($retryAfter);
                if ($when !== false) {
                    $deltaMs = ($when - time()) * 1000;
                    if ($deltaMs > 0) {
                        return $deltaMs;
                    }
                }
            }
        }

        return $this->retryPolicy->delayForAttemptMs($attempt);
    }

    private function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof NetworkExceptionInterface) {
            return true;
        }
        if ($e instanceof ClientExceptionInterface) {
            return true;
        }
        if ($e instanceof BirdeyeException) {
            return $e->httpStatus === 429 || $e->httpStatus >= 500;
        }

        return false;
    }

    private function attempt(string $method, string $endpoint, string $bodyJson, ?string $chain, int $attemptNumber): mixed
    {
        $request = $this->requestFactory->createRequest(strtoupper($method), $this->baseUrl . $endpoint)
            ->withHeader('X-API-KEY', $this->apiKey);

        if ($chain !== null && $chain !== '') {
            $request = $request->withHeader('x-chain', $chain);
        }
        if ($bodyJson !== '') {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($bodyJson));
        }

        $this->logger->debug('birdeye: request', ['method' => $method, 'path' => $endpoint, 'chain' => $chain]);

        $response = $this->httpClient->sendRequest($request);
        $rawBody = (string) $response->getBody();
        $status = $response->getStatusCode();
        $headers = $response->getHeaders();

        $decoded = json_decode($rawBody, true);
        $success = is_array($decoded) && ($decoded['success'] ?? false) === true;
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? '') : '';

        $this->recordResponseMeta($status, $success, $message, $rawBody, $headers, $attemptNumber);

        $this->throwOnHttpError($status, $success, $message, $rawBody, $headers);

        if (!is_array($decoded)) {
            throw new BirdeyeException($status, false, 'Invalid JSON response envelope', $rawBody, $headers);
        }

        if (!$success) {
            throw new BirdeyeException($status, false, $message, $rawBody, $headers);
        }

        return $decoded['data'] ?? null;
    }

    private function recordResponseMeta(int $status, bool $success, string $message, string $rawBody, array $headers, int $attemptNumber): void
    {
        $this->lastResponseMeta = new ResponseMeta(
            httpStatus: $status,
            success: $success,
            message: $message,
            rawBody: $rawBody,
            headers: $headers,
            attempts: $attemptNumber,
        );
    }

    /**
     * Maps HTTP status to a typed exception. Birdeye does not publish a
     * full HTTP-status catalog as of this SDK's last documentation
     * pass, so unlisted 4xx/5xx codes fall back to the nearest sentinel
     * exception rather than being silently ignored.
     *
     * @param array<string, array<int, string>> $headers
     */
    private function throwOnHttpError(int $status, bool $success, string $message, string $rawBody, array $headers): void
    {
        $exceptionClass = match (true) {
            $status === 400 => InvalidRequestException::class,
            $status === 401 => AuthenticationException::class,
            $status === 403 => ForbiddenException::class,
            $status === 404 => NotFoundException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            $status >= 400 => InvalidRequestException::class,
            default => null,
        };

        if ($exceptionClass !== null) {
            throw new $exceptionClass($status, $success, $message, $rawBody, $headers);
        }
    }
}
