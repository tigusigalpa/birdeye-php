<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye\Http;

/**
 * Captures everything about a REST response beyond the decoded payload:
 * HTTP status, the envelope's success/message fields, the raw body, and
 * every response header verbatim. Headers is exposed in full (rather
 * than picking out named rate-limit fields) because Birdeye's docs do
 * not document a stable set of rate-limit header names — inspect
 * Headers yourself for whatever your plan tier sends rather than
 * trusting an SDK-invented field name.
 *
 * @phpstan-type HeaderMap array<string, array<int, string>>
 */
final class ResponseMeta
{
    /**
     * @param HeaderMap $headers
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly bool $success,
        public readonly string $message,
        public readonly string $rawBody,
        public readonly array $headers,
        public readonly int $attempts,
        public readonly ?string $code = null,
        public readonly ?string $requestId = null,
    ) {
    }
}
