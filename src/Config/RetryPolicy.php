<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye\Config;

/**
 * Controls automatic retries for idempotent (GET) REST calls only. Never
 * applied to POST requests, since Birdeye's docs do not document
 * idempotency keys for them.
 */
final class RetryPolicy
{
    /**
     * @param int $maxAttempts Total attempts including the first. 1 disables retries.
     * @param int $baseDelayMs Base for exponential backoff (with full jitter), used when the response carries no Retry-After header.
     * @param int $maxDelayMs Upper bound for a single backoff delay.
     * @param int $maxElapsedMs Caps total time spent retrying a single call.
     * @param (callable(int $attempt, int $delayMs, \Throwable $error): void)|null $onRetry
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $baseDelayMs = 250,
        public readonly int $maxDelayMs = 5000,
        public readonly int $maxElapsedMs = 20000,
        public readonly mixed $onRetry = null,
    ) {
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1.');
        }
        if ($this->baseDelayMs < 0 || $this->maxDelayMs < 0 || $this->maxElapsedMs < 0) {
            throw new \InvalidArgumentException('Retry delays and maxElapsedMs must not be negative.');
        }
        if ($this->onRetry !== null && !is_callable($this->onRetry)) {
            throw new \InvalidArgumentException('onRetry must be callable or null.');
        }
    }

    public static function default(): self
    {
        return new self();
    }

    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    public function delayForAttemptMs(int $attempt): int
    {
        if ($this->baseDelayMs <= 0) {
            return 0;
        }
        $backoff = $this->baseDelayMs;
        for ($i = 1; $i < $attempt && $backoff <= intdiv($this->maxDelayMs, 2); $i++) {
            $backoff *= 2;
        }

        return random_int(0, min($backoff, $this->maxDelayMs));
    }
}
