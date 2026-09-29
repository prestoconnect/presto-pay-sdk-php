# Changelog

## Unreleased

- Initial PHP 8.2+ SDK implementation for payment init, query, reversal and refund requests.
- Signed request and response handling, webhook verification and acknowledgement, cURL and PSR-18 transports.
- Shared wire vectors, Composer package metadata, CI matrix and merchant documentation.
- `CurlTransport` now classifies TLS/certificate handshake failures as `requestNotSent`, alongside DNS and connect
  failures; a socket suite proves the classification against real loopback servers.
- `Retry-After` is honoured for retried reads, and backoff bounds (`initialBackoff`, `maxBackoff`) are configurable.
- PHP-CS-Fixer added to `composer check` and CI.
