<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye\Config;

/**
 * The real wall clock; used unless a ClockInterface is injected.
 */
final class SystemClock implements ClockInterface
{
    public function nowMillis(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
