<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye\Exception;

/**
 * Thrown before a response body exceeds the SDK's safety limit.
 */
final class ResponseTooLargeException extends \RuntimeException
{
    public function __construct(int $maxBytes)
    {
        parent::__construct(sprintf('Birdeye API response body exceeds the %d-byte limit.', $maxBytes));
    }
}
