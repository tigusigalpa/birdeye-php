# Endpoint Registry

Every implemented endpoint, its HTTP method, exact Birdeye route, SDK method, and the official documentation URL it was built against. This is a hand-maintained list, not generated — keep it in sync when adding endpoints.

| SDK Method | HTTP Method | Route | Official Docs |
|---|---|---|---|
| `Price\Client::getPrice` | GET | `/defi/price` | https://docs.birdeye.so/reference/get-defi-price |
| `Price\Client::getMultiPrice` | GET | `/defi/multi_price` | https://docs.birdeye.so/reference/get-defi-multi_price |
| `Price\Client::getMultiPricePost` | POST | `/defi/multi_price` | https://docs.birdeye.so/reference/post-defi-multi_price |
| `Price\Client::getHistoricalPriceByUnixTime` | GET | `/defi/historical_price_unix` | https://docs.birdeye.so/reference/get-defi-historical_price_unix |
| `Price\Client::getHistoricalPriceSeries` | GET | `/defi/history_price` | https://docs.birdeye.so/reference/get-defi-history_price |
| `Price\Client::getOhlcvV3` | GET | `/defi/v3/ohlcv` | https://docs.birdeye.so/reference/get-defi-v3-ohlcv |
| `Price\Client::getOhlcvV3Pair` | GET | `/defi/v3/ohlcv/pair` | https://docs.birdeye.so/reference/get-defi-v3-ohlcv-pair |
| `Price\Client::getOhlcvBaseQuote` | GET | `/defi/ohlcv/base_quote` | https://docs.birdeye.so/reference/get-defi-ohlcv-base_quote |
| `Price\Client::getPriceVolume` | GET | `/defi/price_volume/single` | https://docs.birdeye.so/reference/get-defi-price_volume-single |
| `Price\Client::getMultiPriceVolume` | POST | `/defi/price_volume/multi` | https://docs.birdeye.so/reference/post-defi-price_volume-multi |

## Deferred (not yet implemented)

Token/market data, transactions, wallets, balances, holders, identity, Perps, blockchain data, x402, and WebSocket subscriptions are not yet mapped as typed services. The deprecated `/defi/ohlcv` and `/defi/ohlcv/pair` routes are intentionally excluded. Each listed route has a mock-based unit test; the raw request methods cover a documented endpoint until a tested typed method is added.
