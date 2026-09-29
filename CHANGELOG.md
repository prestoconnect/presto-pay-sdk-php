# Changelog

## Unreleased

## 0.1.0 - 2026-09-29

- Initial PHP 8.2+ SDK implementation for payment init, query, reversal and refund requests.
- Signed request and response handling, webhook verification and acknowledgement, cURL and PSR-18 transports.
- Shared wire vectors, Composer package metadata, CI matrix and merchant documentation.
- `CurlTransport` now classifies TLS/certificate handshake failures as `requestNotSent`, alongside DNS and connect
  failures; a socket suite proves the classification against real loopback servers.
- `Retry-After` is honoured for retried reads, and backoff bounds (`initialBackoff`, `maxBackoff`) are configurable.
- PHP-CS-Fixer added to `composer check` and CI.
- Plain PHP, Laravel and Symfony webhook handler examples in [`docs/webhooks.md`](docs/webhooks.md), each
  deduplicating on `eventRefNum` with a database-level unique constraint.
- `sample/my-store/` — a runnable checkout app against Presto staging, with no framework and no dependencies
  beyond the SDK.
- Fixed: `init`/`reverse`/`refund` are now retried when a transport failure proves the request was never sent
  (`requestNotSent`), matching the Go SDK and the documented retry policy; previously a write was never retried
  even in that safe case.
- Added `PaymentStatus::PENDING_REVERSE` and `PENDING_REFUND`.
- `sample/laravel-store/` and `sample/symfony-store/` — the same checkout app rebuilt in Laravel 13 and
  Symfony 7.4, each linking the SDK via a Composer path repository.
- Fixed: the `User-Agent` header sent a hardcoded `0.1.0` independent of `PrestoPay::VERSION`. Both now come
  from a single `Internal\SdkVersion::CURRENT` constant.
- Added `homepage`, `authors` and `support` to `composer.json`, and `SECURITY.md` / `CONTRIBUTING.md`.
- Fixed: `PrestoPay::post()` compared a gateway error code against the literal `'1203'` instead of
  `ErrorCode::DUPLICATE_TXN_REF_NUM`.
- Fixed: the Symfony sample's webhook dedupe was a non-atomic check-then-set, so two concurrent deliveries of
  the same `eventRefNum` could both run fulfilment logic; it now holds a `symfony/lock` for the check-and-set.
- Fixed: the Laravel sample accepted `amountInRinggit` values below 0.01 past validation, surfacing the
  SDK's rejection as a 502 instead of the intended 400 field error.
