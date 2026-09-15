# Birdeye PHP/Laravel Client/SDK/Library

![BirdEye Laravel PHP SDK](https://i.postimg.cc/8P91nNkY/birdeye-php-laravel-hero.jpg)

[![CI](https://github.com/tigusigalpa/birdeye-php/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/tigusigalpa/birdeye-php/actions/workflows/ci.yml)
[![Tests](https://github.com/tigusigalpa/birdeye-php/actions/workflows/test.yml/badge.svg?branch=main)](https://github.com/tigusigalpa/birdeye-php/actions/workflows/test.yml)
[![Coverage](https://github.com/tigusigalpa/birdeye-php/actions/workflows/coverage.yml/badge.svg?branch=main)](https://github.com/tigusigalpa/birdeye-php/actions/workflows/coverage.yml)
[![CodeQL](https://github.com/tigusigalpa/birdeye-php/actions/workflows/codeql.yml/badge.svg?branch=main)](https://github.com/tigusigalpa/birdeye-php/actions/workflows/codeql.yml)
[![Codecov](https://codecov.io/gh/tigusigalpa/birdeye-php/graph/badge.svg)](https://codecov.io/gh/tigusigalpa/birdeye-php)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![Laravel 10-13](https://img.shields.io/badge/Laravel-10--13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com/)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE)

A small, dependable PHP client for the [Birdeye public API](https://docs.birdeye.so/reference/birdeye-api-getting-started). It works well in Laravel applications, workers, dashboards, and plain PHP scripts that need token prices and chart data without giving up control of HTTP, errors, or retries.

The SDK adds the API key and chain headers, decodes Birdeye's JSON envelope, maps HTTP failures to typed exceptions, records request metadata, and uses conservative retries. It is PSR-18/PSR-17 based, so Guzzle is convenient but never baked into the core client.

Maintained by [Igor Sazonov](https://github.com/tigusigalpa) · [sovletig@gmail.com](mailto:sovletig@gmail.com). This is an independent community project, not an official Birdeye SDK. A matching [Go SDK](https://github.com/tigusigalpa/birdeye-go) is also available.

## Contents

- [Requirements and installation](#requirements-and-installation)
- [Configure the client](#configure-the-client)
- [Quick start](#quick-start)
- [Common recipes](#common-recipes)
- [Supported API surface](#supported-api-surface)
- [Errors and retries](#errors-and-retries)
- [Use an endpoint before it is typed](#use-an-endpoint-before-it-is-typed)
- [Examples, testing, and security](#examples-testing-and-security)

## Requirements and installation

This package requires PHP `8.2+`. Laravel 10–13 integration is optional.

For Laravel or a plain PHP project, install the package together with a PSR-18 client and PSR-17 factories:

```bash
composer require tigusigalpa/birdeye-php guzzlehttp/guzzle guzzlehttp/psr7
```

You may replace Guzzle with the PSR-compatible HTTP stack you already use.

Create an API key in Birdeye, then keep it outside the repository:

```bash
export BIRDEYE_API_KEY="your-key"
```

On PowerShell:

```powershell
$env:BIRDEYE_API_KEY = "your-key"
```

Use `.env.example` as a reminder of the variable names. Do not commit a populated `.env` file.

## Configure the client

Every request receives `X-API-KEY` automatically. Most DeFi routes also use `x-chain`; set an application default once and override it only where a call needs another chain.

### Laravel

The service provider and `Birdeye` facade are discovered automatically. Publish the configuration once if you want to keep it in your application:

```bash
php artisan vendor:publish --tag=birdeye-config
```

```env
BIRDEYE_API_KEY=your-key
BIRDEYE_DEFAULT_CHAIN=solana
# BIRDEYE_BASE_URL=https://public-api.birdeye.so
```

Then inject `Tigusigalpa\Birdeye\Client` where it is needed. Resolving the client does not make a network request.

### Plain PHP

```php
<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Tigusigalpa\Birdeye\Client;

$apiKey = getenv('BIRDEYE_API_KEY')
    ?: throw new RuntimeException('Set BIRDEYE_API_KEY before starting the application.');

$factory = new HttpFactory();
$birdeye = new Client(
    httpClient: new GuzzleClient(),
    requestFactory: $factory,
    streamFactory: $factory,
    apiKey: $apiKey,
    defaultChain: 'solana',
);
```

A call-level chain wins over the client's default:

```php
$price = $birdeye->price->getPrice('0x...', chain: 'ethereum');
```

The SDK does not guess which chains or endpoints your Birdeye plan permits. Birdeye remains the source of truth and returns a typed error if access is denied.

## Quick start

Fetch the current USD price of wrapped SOL from a Laravel controller:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Tigusigalpa\Birdeye\Client;

final class TokenPriceController
{
    public function __invoke(Client $birdeye): array
    {
        $quote = $birdeye->price->getPrice(
            'So11111111111111111111111111111111111111112',
        );

        return [
            'price_usd' => $quote['value'] ?? null,
            'change_24h' => $quote['priceChange24h'] ?? null,
            'updated_at' => $quote['updateHumanTime'] ?? null,
        ];
    }
}
```

For a one-off script, reuse `$birdeye` from the previous section:

```php
$quote = $birdeye->price->getPrice(
    'So11111111111111111111111111111111111111112',
);

printf(
    "SOL: $%.4f (24h: %.2f%%)\n",
    $quote['value'] ?? 0,
    $quote['priceChange24h'] ?? 0,
);
```

## Common recipes

The snippets below assume that `$birdeye` has already been configured.

### Ask for price with a liquidity threshold

```php
use Tigusigalpa\Birdeye\Price\Client as PriceClient;

$quote = $birdeye->price->getPrice(
    address: 'So11111111111111111111111111111111111111112',
    includeLiquidity: true,
    checkLiquidity: 25_000.0,
    uiAmountMode: PriceClient::UI_AMOUNT_MODE_SCALED,
);

printf(
    "price=$%.6f liquidity=$%.2f\n",
    $quote['value'] ?? 0,
    $quote['liquidity'] ?? 0,
);
```

### Load a watchlist in one request

The GET variant is suited to short lists of up to 100 tokens. Birdeye represents an address with no price as `null`; this PHP client omits that address from the result.

```php
$prices = $birdeye->price->getMultiPrice([
    'So11111111111111111111111111111111111111112', // wrapped SOL
    'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v', // USDC
]);

foreach ($prices as $address => $quote) {
    printf("%s: $%.6f\n", $address, $quote['value'] ?? 0);
}
```

For a longer list, use the POST variant. It sends Birdeye's documented comma-separated address list as JSON and is never retried automatically:

```php
$prices = $birdeye->price->getMultiPricePost(
    addresses: $addresses,
    includeLiquidity: true,
);
```

### Draw a 24-hour candle chart

V3 OHLCV supports fine intervals, count mode, padding, and outlier control. Use Unix seconds for `time_from` and `time_to`.

```php
$now = time();
$page = $birdeye->price->getOhlcvV3([
    'address' => 'So11111111111111111111111111111111111111112',
    'type' => '1H',
    'time_from' => $now - 24 * 60 * 60,
    'time_to' => $now,
    'currency' => 'usd',
]);

foreach ($page['items'] ?? [] as $candle) {
    printf(
        "%s close=$%.4f volume=%.2f\n",
        gmdate(DATE_ATOM, $candle['unix_time']),
        $candle['c'],
        $candle['v'],
    );
}
```

Use `getOhlcvV3Pair()` for a specific pool. For a base/quote market, call `getOhlcvBaseQuote()`:

```php
$candles = $birdeye->price->getOhlcvBaseQuote([
    'base_address' => 'base-token-address',
    'quote_address' => 'quote-token-address',
    'type' => '1H',
    'time_from' => strtotime('-1 day'),
    'time_to' => time(),
]);
```

### Get historical prices

For a point-in-time price, ask for a Unix timestamp:

```php
$priceOnNewYear = $birdeye->price->getHistoricalPriceByUnixTime(
    address: 'So11111111111111111111111111111111111111112',
    unixTime: strtotime('2025-01-01 00:00:00 UTC'),
);

printf("historical price: $%.6f\n", $priceOnNewYear['value'] ?? 0);
```

For a time series, call `getHistoricalPriceSeries()`. Birdeye does not publish a stable field schema for this route, so the SDK preserves the response fields as they arrive:

```php
$series = $birdeye->price->getHistoricalPriceSeries([
    'address' => 'So11111111111111111111111111111111111111112',
    'address_type' => 'token',
    'type' => '1H',
    'time_from' => strtotime('-7 days'),
    'time_to' => time(),
]);

foreach ($series['items'] ?? [] as $point) {
    // Inspect once, then map fields into your own application DTO.
    var_dump($point);
}
```

### Add price and rolling volume to a token card

```php
$snapshot = $birdeye->price->getPriceVolume(
    address: 'So11111111111111111111111111111111111111112',
    type: '24h',
);

// This route has no stable upstream schema, so do not assume field names.
var_export($snapshot);
```

For several tokens, use the batch POST endpoint:

```php
$snapshots = $birdeye->price->getMultiPriceVolume(
    addresses: $addresses,
    type: '24h',
);
```

## Supported API surface

| Area | Methods | Response |
|---|---|---|
| Spot prices | `getPrice`, `getMultiPrice`, `getMultiPricePost` | Price arrays |
| Historical prices | `getHistoricalPriceByUnixTime`, `getHistoricalPriceSeries` | Price array / raw response array |
| Candles | `getOhlcvV3`, `getOhlcvV3Pair`, `getOhlcvBaseQuote` | V3 candle page / raw response array |
| Rolling activity | `getPriceVolume`, `getMultiPriceVolume` | Raw response array |

See [docs/endpoints.md](docs/endpoints.md) for the exact route-to-method map and each official Birdeye reference.

The upstream-deprecated `/defi/ohlcv` and `/defi/ohlcv/pair` routes are intentionally unsupported. Wallets, transactions, holders, token lists, Perps, blockchain data, x402, and WebSockets are not yet mapped as typed services.

## Errors and retries

All upstream API failures extend `Tigusigalpa\Birdeye\Exception\BirdeyeException`. Catch a narrow error when your application has a specific recovery path, then use the base exception for diagnostics.

```php
use Tigusigalpa\Birdeye\Exception\AuthenticationException;
use Tigusigalpa\Birdeye\Exception\BirdeyeException;
use Tigusigalpa\Birdeye\Exception\ForbiddenException;
use Tigusigalpa\Birdeye\Exception\RateLimitException;

try {
    $quote = $birdeye->price->getPrice($address);
} catch (AuthenticationException) {
    throw new RuntimeException('Check BIRDEYE_API_KEY.');
} catch (ForbiddenException) {
    throw new RuntimeException('This endpoint is unavailable on the current Birdeye plan.');
} catch (RateLimitException) {
    // Queue the job or tell the caller to retry later.
    throw new RuntimeException('Birdeye rate limit reached.');
} catch (BirdeyeException $e) {
    error_log(sprintf(
        'Birdeye failed: status=%d code=%s request_id=%s',
        $e->httpStatus,
        $e->birdeyeCode ?? '-',
        $e->requestId ?? '-',
    ));

    throw $e;
}
```

The default policy makes up to three attempts for `GET` requests after HTTP `429` or a transient PSR-18 network failure. Backoff is bounded, jittered, and honours `Retry-After`. The SDK never retries server errors or POST requests on its own.

To disable automatic retries, pass `RetryPolicy::none()` when constructing the client:

```php
use Tigusigalpa\Birdeye\Config\RetryPolicy;

$birdeye = new Client(
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
    apiKey: $apiKey,
    retryPolicy: RetryPolicy::none(),
);
```

For troubleshooting, `$birdeye->getExecutor()->getLastResponseMeta()` exposes the HTTP status, headers, attempt count, request ID, and a response body capped at 10 MiB.

## Use an endpoint before it is typed

`Client::request()` is an escape hatch for a new Birdeye route. It still applies authentication, chain selection, query serialization, envelope decoding, error mapping, and the same retry policy.

```php
$data = $birdeye->request(
    'GET',
    '/defi/token_overview',
    ['address' => $address],
);
```

Some endpoint families require another header. `requestWithHeaders()` supports that without allowing a call to replace your configured API key or explicit chain:

```php
$data = $birdeye->requestWithHeaders(
    'GET',
    '/perps/v1/token/list',
    ['x-perp' => 'true'],
    chain: 'solana',
);
```

## Examples, testing, and security

Executable examples live in [examples/](examples). They make real Birdeye calls, so set `BIRDEYE_API_KEY` first:

```bash
php examples/get_price.php
php examples/get_ohlcv.php
php examples/multi_price.php
php examples/error_handling.php
```

The test suite is fully offline: mocked PSR-18 responses mean it never spends Birdeye Compute Units or requires an API key.

```bash
composer install
composer test
composer stan
vendor/bin/php-cs-fixer fix --dry-run --diff
```

GitHub Actions runs CI, PHPUnit on PHP 8.2–8.4, Clover coverage upload to Codecov, and CodeQL analysis. Keep production API keys in your deployment platform's secret store, rotate them if exposed, and never log a key or full upstream body.

## License

MIT. See [LICENSE](LICENSE).
