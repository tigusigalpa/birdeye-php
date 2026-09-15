# Birdeye PHP/Laravel Client/SDK/Library

A PHP client for the [Birdeye](https://birdeye.so) crypto market data API (`https://public-api.birdeye.so`), written from scratch against Birdeye's official documentation — not generated code. PSR-18/PSR-17 based (no hardcoded Guzzle), with optional Laravel 10–13 integration.

**Author:** Igor Sazonov — [sovletig@gmail.com](mailto:sovletig@gmail.com) — [github.com/tigusigalpa](https://github.com/tigusigalpa)

**Package:** a matching Go SDK is available at `tigusigalpa/birdeye-go`.

---

## Status

**This is an early, honest checkpoint, not a finished library.** Only the **Price & OHLCV** family is implemented and tested: single/multi-token real-time price, v3 OHLCV candles (token and pair), and historical price by Unix timestamp. Every other documented family — token/pair stats, token/market lists, transactions, wallet/net-worth/PnL, balance/transfer, holders, Perps Data API, Blockchain Data API, x402, and WebSocket subscriptions — is **not yet implemented**. See [docs/endpoints.md](docs/endpoints.md) for the exact, hand-maintained list of what's covered, with a direct Birdeye documentation link per method.

We'd rather ship a small, correct surface than a large, half-tested one. If you need broader coverage today, use the raw request escape hatch (`Client::request()`) to call any endpoint this SDK hasn't mapped yet.

> **Note on `orchestra/testbench`:** the original brief called for Laravel-integration tests via Orchestra Testbench. In this development environment, `orchestra/testbench` could not be installed — every currently released `laravel/framework` version (10.x through 11.x, transitively required by every testbench release) is blocked by a standing composer security-advisory policy (`policy.advisories.block`) configured globally on this machine. This is a deliberate security control, not something this SDK should override unilaterally. The core SDK (HTTP transport, Price service) is fully covered by PHPUnit tests that don't need Laravel at all; `Laravel\BirdeyeServiceProvider` itself is exercised only by consumers running inside a real Laravel app. If your environment can install Testbench, adding integration tests for the service provider is a natural next step.

---

## Why this exists

Building each endpoint by hand, one at a time, against Birdeye's real documentation — with typed requests/responses, tests, and a docs entry — trades coverage speed for correctness: what's here is verified against the docs, not guessed. Where a response field's exact shape couldn't be confirmed, this SDK does not fabricate it.

---

## Install

```bash
composer require tigusigalpa/birdeye-php
```

Plain PHP apps also need a PSR-18 client + PSR-17 factories — e.g.:

```bash
composer require guzzlehttp/guzzle guzzlehttp/psr7
```

### Laravel setup

The service provider and `Birdeye` facade are auto-discovered. If `guzzlehttp/guzzle` + `guzzlehttp/psr7` are installed, a default PSR-18/17 binding is wired up automatically; otherwise the container throws a clear error naming what to install or bind yourself.

```bash
php artisan vendor:publish --tag=birdeye-config
```

```env
BIRDEYE_API_KEY=your-api-key
BIRDEYE_DEFAULT_CHAIN=solana
```

```php
use Tigusigalpa\Birdeye\Client;

class PriceController
{
    public function __construct(private readonly Client $birdeye) {}

    public function index()
    {
        return $this->birdeye->price->getPrice('So11111111111111111111111111111111111111112');
    }
}
```

### Plain PHP (no Laravel)

```php
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Tigusigalpa\Birdeye\Client;

$factory = new HttpFactory();
$client = new Client(
    httpClient: new GuzzleClient(),
    requestFactory: $factory,
    streamFactory: $factory,
    apiKey: getenv('BIRDEYE_API_KEY'),
    defaultChain: 'solana',
);

$price = $client->price->getPrice('So11111111111111111111111111111111111111112');
echo $price['value'];
```

Runnable examples: [examples/](examples/) — `get_price.php`, `get_ohlcv.php`, `multi_price.php`, `error_handling.php`.

---

## Authentication and configuration

Read `BIRDEYE_API_KEY` from an environment variable or Laravel config — never hardcode it. Birdeye requires the `X-API-KEY` header on every request; the SDK sends it automatically from the `apiKey` constructor argument. Some endpoint families also require an `x-chain` header (Birdeye defaults to `"solana"` server-side when it's omitted). Set a client-wide default via `defaultChain`, or override it per call via each method's `$chain` parameter:

```php
$price = $client->price->getPrice('addr', chain: 'ethereum');
```

Data accessibility (which endpoints/chains you can call) is determined by your Birdeye plan (Standard/Lite/Starter/Premium/Business/Enterprise). This SDK does **not** validate plan access client-side — a request Birdeye rejects for your plan throws a normal typed exception (`Exception\ForbiddenException`), same as any other error.

---

## Supported services

| Service | Docs |
|---|---|
| `$client->price` | [Price & OHLCV overview](https://docs.birdeye.so/reference/price-ohlcv) |

Full per-method mapping: [docs/endpoints.md](docs/endpoints.md).

---

## Error handling and retries

Every error is a typed exception under `Tigusigalpa\Birdeye\Exception\` — `AuthenticationException` (401), `ForbiddenException` (403), `NotFoundException` (404), `RateLimitException` (429), `InvalidRequestException` (400), `ServerException` (5xx), or the base `BirdeyeException` for anything else — carrying the exact HTTP status, message, and raw response body Birdeye sent, nothing silently dropped:

```php
use Tigusigalpa\Birdeye\Exception\BirdeyeException;

try {
    $client->price->getPrice($address);
} catch (BirdeyeException $e) {
    echo $e->httpStatus, ' ', $e->getMessage();
}
```

GET requests retry automatically (bounded exponential backoff with full jitter, honoring a `Retry-After` response header when present) via a conservative default `RetryPolicy` (3 attempts, 250ms-5s backoff, 20s max elapsed). POST requests are **never** auto-retried. Override with a custom `RetryPolicy` passed to `Client`, or disable retries entirely with `RetryPolicy::none()`.

---

## Raw request escape hatch

Call any Birdeye endpoint — including ones this SDK hasn't mapped to a typed method yet — without waiting for an SDK update:

```php
$data = $client->request('GET', '/defi/token_overview', ['address' => $addr]);
```

---

## Testing and development

```bash
composer install
vendor/bin/phpunit         # or: composer test
vendor/bin/phpstan analyse # or: composer stan — level 5
vendor/bin/php-cs-fixer fix # or: composer cs
```

Unit tests run fully offline against a mocked Guzzle/PSR-18 transport (`MockHandler`) — no network access or API key required. No test or example in this repository consumes Birdeye Compute Units.

---

## Security notice

This is an unofficial, community-maintained client. Never commit a real `BIRDEYE_API_KEY` — use `.env.example` as a template and keep your actual `.env` out of version control (already gitignored here). This SDK never logs your API key or full response bodies. A published `config/birdeye.php` reads secrets from `env()` — it never contains actual secret values.

---

## Compatibility

Pre-1.0: breaking changes may happen between minor versions while coverage is being built out. Not affiliated with Birdeye.

## License

MIT. See [LICENSE](LICENSE).

## Author

Igor Sazonov — [@tigusigalpa](https://github.com/tigusigalpa) — sovletig@gmail.com

## Links

- [Birdeye API documentation](https://docs.birdeye.so/reference/birdeye-api-getting-started)
- [Repository](https://github.com/tigusigalpa/birdeye-php)
