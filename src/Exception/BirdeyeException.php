<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye\Exception;

/**
 * Base exception for every error returned by Birdeye's REST API. Message
 * is decoded opportunistically from a top-level "message" field if the
 * response body has one — Birdeye's docs do not document a stable error
 * envelope schema, so rawResponse always preserves the exact response
 * body verbatim rather than risking silently dropped error detail.
 */
class BirdeyeException extends \RuntimeException
{
    /**
     * @param array<string, array<int, string>> $headers Response headers, verbatim — e.g. to read Retry-After yourself.
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly bool $success,
        string $birdeyeMessage,
        public readonly string $rawResponse = '',
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Birdeye API error: http=%d success=%s message=%s', $httpStatus, $success ? 'true' : 'false', $birdeyeMessage),
            0,
            $previous,
        );
    }
}
