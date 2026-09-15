<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tigusigalpa\Birdeye\Config\RetryPolicy;
use Tigusigalpa\Birdeye\Exception\AuthenticationException;
use Tigusigalpa\Birdeye\Exception\BirdeyeException;
use Tigusigalpa\Birdeye\Exception\ForbiddenException;
use Tigusigalpa\Birdeye\Exception\RateLimitException;
use Tigusigalpa\Birdeye\Exception\ResponseTooLargeException;
use Tigusigalpa\Birdeye\Http\RequestExecutor;
use Tigusigalpa\Birdeye\Tests\TestCase;

class RequestExecutorTest extends TestCase
{
    private function executorWithMockedResponses(array $responses, array &$history = [], ?string $defaultChain = null, ?RetryPolicy $retryPolicy = null, string $baseUrl = 'https://api.birdeye.test'): RequestExecutor
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $httpClient = new GuzzleClient(['handler' => $stack]);
        $factory = new HttpFactory();

        return new RequestExecutor(
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: $baseUrl,
            apiKey: 'test-key',
            defaultChain: $defaultChain,
            retryPolicy: $retryPolicy ?? RetryPolicy::none(),
        );
    }

    public function test_request_sets_api_key_header(): void
    {
        $history = [];
        $executor = $this->executorWithMockedResponses([
            new Response(200, [], json_encode(['success' => true, 'data' => []])),
        ], $history);

        $executor->request('GET', '/defi/price');

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        $this->assertSame('test-key', $request->getHeaderLine('X-API-KEY'));
    }

    public function test_request_sets_chain_header_only_when_non_empty(): void
    {
        $history = [];
        $executor = $this->executorWithMockedResponses([
            new Response(200, [], json_encode(['success' => true, 'data' => []])),
            new Response(200, [], json_encode(['success' => true, 'data' => []])),
        ], $history);

        $executor->request('GET', '/defi/price');
        $executor->request('GET', '/defi/price', chain: 'ethereum');

        /** @var RequestInterface $first */
        $first = $history[0]['request'];
        /** @var RequestInterface $second */
        $second = $history[1]['request'];
        $this->assertFalse($first->hasHeader('x-chain'));
        $this->assertSame('ethereum', $second->getHeaderLine('x-chain'));
    }

    public function test_request_chain_override_beats_default_chain(): void
    {
        $history = [];
        $executor = $this->executorWithMockedResponses([
            new Response(200, [], json_encode(['success' => true, 'data' => []])),
        ], $history, defaultChain: 'solana');

        $executor->request('GET', '/defi/price', chain: 'ethereum');

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        $this->assertSame('ethereum', $request->getHeaderLine('x-chain'));
    }

    public function test_request_with_headers_preserves_endpoint_header_but_not_api_key_or_chain(): void
    {
        $history = [];
        $executor = $this->executorWithMockedResponses([
            new Response(200, [], json_encode(['success' => true, 'data' => []])),
        ], $history, defaultChain: 'solana');

        $executor->requestWithHeaders('GET', '/perps/v1/token/list', [
            'X-API-KEY' => 'untrusted-key',
            'x-chain' => 'sui',
            'x-perp' => 'true',
        ], chain: 'ethereum');

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        $this->assertSame('test-key', $request->getHeaderLine('X-API-KEY'));
        $this->assertSame('ethereum', $request->getHeaderLine('x-chain'));
        $this->assertSame('true', $request->getHeaderLine('x-perp'));
    }

    public function test_request_normalizes_base_url_and_path_slashes(): void
    {
        $history = [];
        $executor = $this->executorWithMockedResponses([
            new Response(200, [], json_encode(['success' => true, 'data' => []])),
        ], $history, baseUrl: 'https://api.birdeye.test/');

        $executor->request('GET', '/defi/price');

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        $this->assertSame('https://api.birdeye.test/defi/price', (string) $request->getUri());
    }

    public function test_request_decodes_data(): void
    {
        $executor = $this->executorWithMockedResponses([
            new Response(200, [], json_encode(['success' => true, 'data' => ['value' => 1.23]])),
        ]);

        $data = $executor->request('GET', '/defi/price');
        $this->assertSame(1.23, $data['value']);
    }

    public function test_request_null_data_returns_empty_array(): void
    {
        $executor = $this->executorWithMockedResponses([
            new Response(200, [], json_encode(['success' => true, 'data' => null])),
        ]);

        $this->assertSame([], $executor->request('GET', '/defi/price'));
    }

    public function test_request_success_false_throws_birdeye_exception(): void
    {
        $executor = $this->executorWithMockedResponses([
            new Response(200, [], json_encode(['success' => false, 'message' => 'invalid address'])),
        ]);

        try {
            $executor->request('GET', '/defi/price');
            $this->fail('Expected BirdeyeException');
        } catch (BirdeyeException $e) {
            $this->assertFalse($e->success);
            $this->assertStringContainsString('invalid address', $e->getMessage());
        }
    }

    public function test_request_maps_http_401_to_authentication_exception(): void
    {
        $executor = $this->executorWithMockedResponses([
            new Response(401, [], json_encode(['success' => false, 'message' => 'invalid key'])),
        ]);

        $this->expectException(AuthenticationException::class);
        $executor->request('GET', '/defi/price');
    }

    public function test_request_maps_http_403_to_forbidden_exception(): void
    {
        $executor = $this->executorWithMockedResponses([
            new Response(403, [], json_encode(['success' => false, 'message' => 'not on your plan'])),
        ]);

        $this->expectException(ForbiddenException::class);
        $executor->request('GET', '/defi/price');
    }

    public function test_request_maps_http_429_to_rate_limit_exception(): void
    {
        $executor = $this->executorWithMockedResponses([
            new Response(429, [], json_encode(['success' => false, 'message' => 'rate limited'])),
        ]);

        $this->expectException(RateLimitException::class);
        $executor->request('GET', '/defi/price');
    }

    public function test_request_retries_get_requests_after_rate_limit_only(): void
    {
        $history = [];
        $executor = $this->executorWithMockedResponses([
            new Response(429, [], json_encode(['success' => false, 'message' => 'rate limited'])),
            new Response(429, [], json_encode(['success' => false, 'message' => 'rate limited'])),
            new Response(200, [], json_encode(['success' => true, 'data' => []])),
        ], $history, retryPolicy: new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, maxDelayMs: 5, maxElapsedMs: 5000));

        $executor->request('GET', '/defi/price');
        $this->assertCount(3, $history);

        $history2 = [];
        $executor2 = $this->executorWithMockedResponses([
            new Response(500, [], json_encode(['success' => false, 'message' => 'error'])),
        ], $history2, retryPolicy: new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, maxDelayMs: 5, maxElapsedMs: 5000));

        try {
            $executor2->request('POST', '/defi/multi_price', body: ['a' => 'b']);
        } catch (BirdeyeException) {
            // expected
        }
        $this->assertCount(1, $history2);
    }

    public function test_request_does_not_retry_server_errors(): void
    {
        $history = [];
        $executor = $this->executorWithMockedResponses([
            new Response(500, [], json_encode(['success' => false, 'message' => 'error'])),
            new Response(200, [], json_encode(['success' => true, 'data' => []])),
        ], $history, retryPolicy: new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, maxDelayMs: 5, maxElapsedMs: 5000));

        try {
            $executor->request('GET', '/defi/price');
            $this->fail('Expected a server exception.');
        } catch (BirdeyeException) {
            $this->assertCount(1, $history);
        }
    }

    public function test_request_respects_retry_after_header(): void
    {
        $history = [];
        $executor = $this->executorWithMockedResponses([
            new Response(429, ['Retry-After' => '0'], json_encode(['success' => false, 'message' => 'rate limited'])),
            new Response(200, [], json_encode(['success' => true, 'data' => []])),
        ], $history, retryPolicy: new RetryPolicy(maxAttempts: 2, baseDelayMs: 1, maxDelayMs: 5, maxElapsedMs: 5000));

        $executor->request('GET', '/defi/price');
        $this->assertCount(2, $history);
    }

    public function test_get_last_response_meta(): void
    {
        $executor = $this->executorWithMockedResponses([
            new Response(200, [], json_encode(['success' => true, 'data' => ['value' => 1]])),
        ]);

        $executor->request('GET', '/defi/price');
        $meta = $executor->getLastResponseMeta();
        $this->assertNotNull($meta);
        $this->assertSame(200, $meta->httpStatus);
        $this->assertTrue($meta->success);
    }

    public function test_response_meta_and_exception_include_error_code_and_request_id(): void
    {
        $executor = $this->executorWithMockedResponses([
            new Response(400, ['X-Request-ID' => 'request-123'], json_encode(['success' => false, 'code' => 42, 'message' => 'invalid address'])),
        ]);

        try {
            $executor->request('GET', '/defi/price');
            $this->fail('Expected an invalid request exception.');
        } catch (BirdeyeException $e) {
            $this->assertSame('42', $e->birdeyeCode);
            $this->assertSame('request-123', $e->requestId);
        }

        $meta = $executor->getLastResponseMeta();
        $this->assertNotNull($meta);
        $this->assertSame('42', $meta->code);
        $this->assertSame('request-123', $meta->requestId);
    }

    public function test_request_rejects_oversized_response_body(): void
    {
        $executor = $this->executorWithMockedResponses([
            new Response(200, [], str_repeat('x', RequestExecutor::MAX_RESPONSE_BODY_BYTES + 1)),
        ]);

        $this->expectException(ResponseTooLargeException::class);
        $executor->request('GET', '/defi/price');
    }
}
