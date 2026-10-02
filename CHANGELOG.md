# Changelog

## Unreleased

- Webhook guidance now guards on the order record instead of deduplicating on `eventRefNum`: the handler
  queries the payment on every delivery and applies its status with a conditional update that finalises an
  order only once and fulfils only on the change into `Authorised`. Updated the README, `docs/webhooks.md`
  (including the Laravel and Symfony handlers), `docs/production.md`, the wire contract, and the plain PHP,
  Laravel and Symfony samples, whose return page and webhook now share one guarded update.
- New `docs/payment-methods.md`, linked from the README: every payment method code with its SDK constant,
  a note that Presto enables payment methods per merchant during onboarding, which methods need a Presto
  account (the PrestoPay eWallet and Credits, `Card` and the loyalty programmes), which are legacy
  (`TouchNGo`, `BigLife`), and how to pass a code the SDK doesn't list yet.
- The README is now a getting-started guide: creating your key pair and sending Presto the `.der` public key,
  how a payment flows, a four-step quick start and a payment status table. Reference material moved into
  `docs/` (payments and errors, webhooks with complete Laravel and Symfony handlers, production), and
  `docs/production.md` shows how to convert Presto's `.der` certificate to PEM for an inline
  `PRESTOPAY_PUBLIC_KEY`.
- The three samples answer HTTP 401 to a webhook that fails with a `SignatureException`, instead of
  acknowledging it.

## 0.2.0 - 2026-10-01

- **Breaking:** `WebhookEvent::$paymentStatus` is removed. A webhook reports what happened (`eventCode`,
  `success`), not the payment's resulting status, and deriving one was guesswork: a `Refunded` or `Reversed` event
  with `success: false` is a refund or reversal that failed, leaving the payment in its previous status, which the
  event does not carry. Call `payments()->query()` for the current status. This follows the shared wire contract.
- Fixed: `NotifyAck::forThrowable()` answered `Ok` for any `SignatureException` or `ResponseException`, including
  ones from a `query` made inside the webhook handler, which told Presto to stop redelivering an event the
  handler never processed. It now answers `Ok` only when `operation()` is `'webhook'`.
- The three samples query the payment in their webhook handler and list the returned status; a failed query
  answers `NotifyAck::Resend` without recording the event.
- `spec/` updated to `presto-pay-spec` `e8e8177`.
- `sample/laravel-store/` and `sample/symfony-store/` now require `prestouniverse/presto-pay-sdk` from
  Packagist (`^0.1.0`) instead of linking this checkout via a Composer path repository.

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
