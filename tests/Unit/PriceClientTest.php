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
use Tigusigalpa\Birdeye\Http\RequestExecutor;
use Tigusigalpa\Birdeye\Price\Client as PriceClient;
use Tigusigalpa\Birdeye\Tests\TestCase;

class PriceClientTest extends TestCase
{
    private function clientWithResponse(string $jsonBody, array &$history = []): PriceClient
    {
        $mock = new MockHandler([new Response(200, [], $jsonBody)]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $httpClient = new GuzzleClient(['handler' => $stack]);
        $factory = new HttpFactory();

        $executor = new RequestExecutor(
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.birdeye.test',
            apiKey: 'test-key',
        );

        return new PriceClient($executor);
    }

    public function test_get_price(): void
    {
        $history = [];
        $client = $this->clientWithResponse(json_encode([
            'success' => true,
            'data' => ['value' => 123.45, 'updateUnixTime' => 1700000000, 'updateHumanTime' => '2023-11-14T22:13:20', 'priceChange24h' => 1.5],
        ]), $history);

        $price = $client->getPrice('So11111111111111111111111111111111111111112');

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        $this->assertSame('/defi/price', $request->getUri()->getPath());
        $this->assertSame('address=So11111111111111111111111111111111111111112', $request->getUri()->getQuery());
        $this->assertSame(123.45, $price['value']);
    }

    public function test_get_price_sends_optional_params(): void
    {
        $history = [];
        $client = $this->clientWithResponse(json_encode(['success' => true, 'data' => ['value' => 1]]), $history);

        $client->getPrice('addr', includeLiquidity: true, checkLiquidity: 100.0, uiAmountMode: PriceClient::UI_AMOUNT_MODE_SCALED);

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame('true', $query['include_liquidity']);
        $this->assertSame('100', $query['check_liquidity']);
        $this->assertSame('scaled', $query['ui_amount_mode']);
    }

    public function test_get_multi_price_joins_addresses_and_drops_null_entries(): void
    {
        $history = [];
        $client = $this->clientWithResponse(json_encode([
            'success' => true,
            'data' => ['addr1' => ['value' => 1], 'addr2' => null],
        ]), $history);

        $result = $client->getMultiPrice(['addr1', 'addr2']);

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        $this->assertSame('list_address=addr1%2Caddr2', $request->getUri()->getQuery());
        $this->assertArrayHasKey('addr1', $result);
        $this->assertArrayNotHasKey('addr2', $result);
    }

    public function test_get_multi_price_post_sends_comma_string_in_body(): void
    {
        $history = [];
        $client = $this->clientWithResponse(json_encode(['success' => true, 'data' => ['addr1' => ['value' => 1]]]), $history);

        $client->getMultiPricePost(['addr1', 'addr2']);

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('addr1,addr2', $body['list_address']);
    }

    public function test_get_historical_price_by_unix_time(): void
    {
        $history = [];
        $client = $this->clientWithResponse(json_encode([
            'success' => true,
            'data' => ['value' => 50, 'updateUnixTime' => 1700000000, 'priceChange24h' => 0],
        ]), $history);

        $price = $client->getHistoricalPriceByUnixTime('addr', unixTime: 1700000000);

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame('1700000000', $query['unixtime']);
        $this->assertSame(50, $price['value']);
    }

    public function test_get_ohlcv_v3_decodes_candles(): void
    {
        $history = [];
        $client = $this->clientWithResponse(json_encode([
            'success' => true,
            'data' => ['items' => [['o' => 1, 'h' => 2, 'l' => 0.5, 'c' => 1.5, 'v' => 1000, 'v_usd' => 1500, 'unix_time' => 1700000000, 'address' => 'addr', 'type' => '1H', 'currency' => 'usd']], 'is_scaled_ui_token' => false],
        ]), $history);

        $page = $client->getOhlcvV3(['address' => 'addr', 'type' => '1H', 'time_from' => 1700000000, 'time_to' => 1700003600]);

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        $this->assertSame('/defi/v3/ohlcv', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame('1H', $query['type']);
        $this->assertCount(1, $page['items']);
        $this->assertSame(1.5, $page['items'][0]['c']);
    }

    public function test_get_ohlcv_v3_pair_decodes_candles(): void
    {
        $history = [];
        $client = $this->clientWithResponse(json_encode([
            'success' => true,
            'data' => ['items' => [['o' => 1, 'h' => 2, 'l' => 0.5, 'c' => 1.5, 'v' => 1000, 'v_usd' => 1500, 'address' => 'pairAddr', 'type' => '1H', 'unix_time' => 1700000000, 'currency' => 'usd']]],
        ]), $history);

        $page = $client->getOhlcvV3Pair(['address' => 'pairAddr', 'type' => '1H', 'time_from' => 1700000000, 'time_to' => 1700003600]);

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        $this->assertSame('/defi/v3/ohlcv/pair', $request->getUri()->getPath());
        $this->assertCount(1, $page['items']);
        $this->assertSame('pairAddr', $page['items'][0]['address']);
    }

    public function test_get_ohlcv_v3_converts_bool_params_to_string(): void
    {
        $history = [];
        $client = $this->clientWithResponse(json_encode(['success' => true, 'data' => ['items' => []]]), $history);

        $client->getOhlcvV3(['address' => 'addr', 'type' => '1H', 'padding' => true, 'outlier' => false]);

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame('true', $query['padding']);
        $this->assertSame('false', $query['outlier']);
    }
}
