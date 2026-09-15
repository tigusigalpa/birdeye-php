# Changelog

All notable changes to this project are documented here.

## Unreleased

### Added

- Price/OHLCV methods for historical price series, base/quote OHLCV, and
  single and batch price-volume snapshots.
- `Client::requestWithHeaders()` for endpoint-family headers such as
  `x-perp`, without allowing a call to replace the configured API key or
  chain.
- Birdeye error code and request ID on `BirdeyeException` and
  `ResponseMeta`, when the upstream response supplies them.
- A 10 MiB response-body limit to protect consuming applications from an
  unexpectedly large upstream response.
- GitHub Actions workflows for CI, PHPUnit, Clover coverage/Codecov, and
  weekly CodeQL analysis; CI and tests run on PHP 8.2, 8.3, and 8.4.

### Changed

- GET retries now match the maintained Go SDK: retry HTTP 429 and transient
  transport failures only. Server-error responses are returned immediately.
- Custom base URLs and endpoint paths are joined safely regardless of their
  leading or trailing slash.
- Laravel wiring keeps an application's existing PSR-17 request or stream
  factory binding instead of replacing both when only one factory is missing.
