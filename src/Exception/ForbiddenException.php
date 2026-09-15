<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye\Exception;

/**
 * HTTP 403 — the endpoint isn't available on your Birdeye plan
 * (Standard/Lite/Starter/Premium/Business/Enterprise). This SDK never
 * validates plan access client-side; Birdeye's response is the only
 * source of truth.
 */
final class ForbiddenException extends BirdeyeException
{
}
