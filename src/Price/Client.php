<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye\Price;

use Tigusigalpa\Birdeye\Http\RequestExecutor;

/**
 * Birdeye's price and OHLCV endpoints: single and multi-token real-time
 * price, v3 OHLCV candles (token and pair), and historical price lookup
 * by Unix timestamp.
 *
 * Docs: https://docs.birdeye.so/reference/price-ohlcv
 */
final class Client
{
    public const UI_AMOUNT_MODE_RAW = 'raw';
    public const UI_AMOUNT_MODE_SCALED = 'scaled';
    public const UI_AMOUNT_MODE_BOTH = 'both';

    public const OHLCV_MODE_RANGE = 'range';
    public const OHLCV_MODE_COUNT = 'count';

    public const CURRENCY_USD = 'usd';
    public const CURRENCY_NATIVE = 'native';

    public function __construct(private readonly RequestExecutor $executor)
    {
    }

    /**
     * Returns a single token's real-time price. The returned array is
     * empty if Birdeye returned data:null (e.g. an address with no
     * tracked price) — not an error.
     *
     * @return array{value?: float, updateUnixTime?: int, updateHumanTime?: string, priceChange24h?: float, priceInNative?: float, liquidity?: float, isScaledUiToken?: bool, scaledValue?: float, multiplier?: float, scaledPriceInNative?: float}
     *
     * Docs: https://docs.birdeye.so/reference/get-defi-price
     */
    public function getPrice(
        string $address,
        ?bool $includeLiquidity = null,
        ?float $checkLiquidity = null,
        ?string $uiAmountMode = null,
        ?string $chain = null,
    ): array {
        return $this->executor->request('GET', '/defi/price', $this->withoutNulls([
            'address' => $address,
            'include_liquidity' => $includeLiquidity !== null ? ($includeLiquidity ? 'true' : 'false') : null,
            'check_liquidity' => $checkLiquidity,
            'ui_amount_mode' => $uiAmountMode,
        ]), chain: $chain);
    }

    /**
     * Returns real-time prices for up to 100 tokens via the GET variant
     * (addresses passed as a comma-separated query param). Keys with no
     * price data are omitted from the returned array (Birdeye sends
     * them as JSON null; a null value has no meaningful array shape to
     * preserve, so this SDK drops the key rather than keeping a null
     * entry — check isset()/array_key_exists() per address if that
     * distinction matters to you).
     *
     * @param string[] $addresses
     * @return array<string, array<string, mixed>>
     *
     * Docs: https://docs.birdeye.so/reference/get-defi-multi_price
     */
    public function getMultiPrice(
        array $addresses,
        ?bool $includeLiquidity = null,
        ?float $checkLiquidity = null,
        ?string $uiAmountMode = null,
        ?string $chain = null,
    ): array {
        $data = $this->executor->request('GET', '/defi/multi_price', $this->withoutNulls([
            'list_address' => implode(',', $addresses),
            'include_liquidity' => $includeLiquidity !== null ? ($includeLiquidity ? 'true' : 'false') : null,
            'check_liquidity' => $checkLiquidity,
            'ui_amount_mode' => $uiAmountMode,
        ]), chain: $chain);

        return array_filter($data, static fn ($v) => $v !== null);
    }

    /**
     * The POST variant of getMultiPrice() — same semantics, but
     * addresses are sent as a comma-separated string inside the JSON
     * body (confirmed via documentation research: still a delimited
     * string, not a JSON array), useful when the address list is too
     * long for a query string.
     *
     * @param string[] $addresses
     * @return array<string, array<string, mixed>>
     *
     * Docs: https://docs.birdeye.so/reference/post-defi-multi_price
     */
    public function getMultiPricePost(
        array $addresses,
        ?bool $includeLiquidity = null,
        ?float $checkLiquidity = null,
        ?string $uiAmountMode = null,
        ?string $chain = null,
    ): array {
        $data = $this->executor->request('POST', '/defi/multi_price', $this->withoutNulls([
            'include_liquidity' => $includeLiquidity !== null ? ($includeLiquidity ? 'true' : 'false') : null,
            'check_liquidity' => $checkLiquidity,
            'ui_amount_mode' => $uiAmountMode,
        ]), ['list_address' => implode(',', $addresses)], chain: $chain);

        return array_filter($data, static fn ($v) => $v !== null);
    }

    /**
     * Returns a token's price at (or nearest to) a specific Unix
     * timestamp.
     *
     * @return array{isScaledUiToken?: bool, value?: float, updateUnixTime?: int, priceChange24h?: float, scaledValue?: float, multiplier?: float}
     *
     * Docs: https://docs.birdeye.so/reference/get-defi-historical_price_unix
     */
    public function getHistoricalPriceByUnixTime(
        string $address,
        ?int $unixTime = null,
        ?string $uiAmountMode = null,
        ?string $chain = null,
    ): array {
        return $this->executor->request('GET', '/defi/historical_price_unix', $this->withoutNulls([
            'address' => $address,
            'unixtime' => $unixTime,
            'ui_amount_mode' => $uiAmountMode,
        ]), chain: $chain);
    }

    /**
     * Returns v3 OHLCV candles for a token (1s-1M granularity; supports
     * sub-minute intervals unlike the legacy /defi/ohlcv endpoint,
     * which this SDK does not implement). $params keys: address
     * (required), type (required, one of the interval strings, e.g.
     * "1H"), time_from, time_to (Unix seconds), currency
     * (CURRENCY_USD default or CURRENCY_NATIVE), mode (OHLCV_MODE_RANGE
     * default or OHLCV_MODE_COUNT), count_limit (0-5000), padding,
     * outlier (bool), ui_amount_mode.
     *
     * @param array<string, mixed> $params
     * @return array{items: array<int, array<string, mixed>>, is_scaled_ui_token?: bool, multiplier?: float}
     *
     * Docs: https://docs.birdeye.so/reference/get-defi-v3-ohlcv
     */
    public function getOhlcvV3(array $params, ?string $chain = null): array
    {
        return $this->executor->request('GET', '/defi/v3/ohlcv', $this->normalizeOhlcvParams($params), chain: $chain);
    }

    /**
     * Returns v3 OHLCV candles for a trading pair (address is the pair
     * address, not a token address). $params keys: address (required,
     * pair address), type (required), time_from, time_to, mode,
     * count_limit, padding, outlier, inversion (bool). No scaled-value
     * or currency fields are documented for pair OHLCV (unlike token
     * OHLCV).
     *
     * @param array<string, mixed> $params
     * @return array{items: array<int, array<string, mixed>>}
     *
     * Docs: https://docs.birdeye.so/reference/get-defi-v3-ohlcv-pair
     */
    public function getOhlcvV3Pair(array $params, ?string $chain = null): array
    {
        return $this->executor->request('GET', '/defi/v3/ohlcv/pair', $this->normalizeOhlcvParams($params), chain: $chain);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function normalizeOhlcvParams(array $params): array
    {
        foreach (['padding', 'outlier', 'inversion'] as $boolKey) {
            if (isset($params[$boolKey]) && is_bool($params[$boolKey])) {
                $params[$boolKey] = $params[$boolKey] ? 'true' : 'false';
            }
        }

        return $this->withoutNulls($params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function withoutNulls(array $params): array
    {
        return array_filter($params, static fn ($v) => $v !== null);
    }
}
