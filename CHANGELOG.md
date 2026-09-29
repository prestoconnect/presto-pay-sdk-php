# Changelog

## Unreleased

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
