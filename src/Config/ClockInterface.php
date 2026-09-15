<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye\Config;

/**
 * Abstracts time so tests can control timing. Injectable for deterministic
 * retry-delay tests.
 */
interface ClockInterface
{
    public function nowMillis(): int;
}
