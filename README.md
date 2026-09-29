# Presto Pay SDK for PHP

[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)

Standalone, framework-agnostic PHP 8.2+ library for the **Presto Connect** payment gateway. It handles the
parts that are easy to get subtly wrong when integrating a signed payment API by hand: typed request/result
objects, RSA request signing, response and webhook signature verification, and PEM key loading.

- **PHP 8.2+** — no required framework or dependency injection container
- Only `ext-curl`, `ext-json`, `ext-openssl`, and the PSR-18/17 HTTP interfaces (no concrete HTTP client
  dependency; bring your own, or use the bundled cURL transport)
- Immutable, `readonly` request and result objects, and a `readonly` `PrestoPay` client — build once, reuse

This repository is under implementation locally and has not been published to Packagist yet — see
[Install](#install).

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Merchant identity](#merchant-identity)
- [Configuration from environment](#configuration-from-environment)
- [Retries and idempotency](#retries-and-idempotency)
- [Webhooks](#webhooks)
- [Errors](#errors)
- [Custom HTTP client](#custom-http-client)
- [Debugging signatures](#debugging-signatures)
- [Samples](#samples)
- [Contributing](#contributing)
- [License](#license)

## Install

The planned package name is `prestouniverse/presto-pay-sdk`. After publication:

```bash
composer require prestouniverse/presto-pay-sdk
```

For development from this checkout, run `composer install` and include `vendor/autoload.php`.

The SDK requires 64-bit PHP, `ext-curl`, `ext-json` and `ext-openssl`. It runs on PHP 8.2–8.5 in CI.

## Quick start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use PrestoUniverse\PrestoPay\Environment;
use PrestoUniverse\PrestoPay\Key\PrivateKey;
use PrestoUniverse\PrestoPay\Key\PublicKey;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\InitRequest;
use PrestoUniverse\PrestoPay\TxnType;

$presto = new PrestoPay(
    environment: Environment::Staging,
    merchantId: 'YOUR_MID',                                       // mid, sent on every request
    privateKey: PrivateKey::fromFile('/path/to/merchant-key.pem'), // partner private key, request signing
    prestoPublicKeys: [PublicKey::fromFile('/path/to/presto-public-key.der')], // response/webhook verification
);

$payment = $presto->payments()->init(new InitRequest(
    prestoMrn: 'YOUR_PRESTO_MRN', // prestoMrn, required on every request
    txnType: TxnType::WebPay,
    txnRefNum: 'order-123',
    displayDesc: 'Order 123',
    amount: 10_000, // minor currency units
    currencyCode: 'MYR',
    notifyUrl: 'https://your-app.example/presto/notify',
    redirectUrl: 'https://your-app.example/presto/return/order-123',
));

header('Location: ' . $payment->paymentUrl, true, 302);
```

Use a unique `txnRefNum` for each order. The SDK accepts PEM private keys; direct `.p12` loading is pending
compatibility checks across the supported PHP runtimes. See [payments and errors](docs/payments-and-errors.md).

## Merchant identity

One `PrestoPay` instance belongs to one merchant: `merchantId` (`mid`) is required on the constructor, sent on
every request, and `WebhookVerifier` rejects events for any other `mid`. `prestoMrn` is set per request — every
`*Request` object requires it — because one `mid` can have several `prestoMrn`s.

To serve several merchants, build one `PrestoPay` per `mid` (they can share the same keys) and route each
request and webhook to the matching client, for example an array keyed by `mid`.

## Configuration from environment

```php
$presto = PrestoPay::fromEnv([
    'PRESTOPAY_ENV' => 'staging',
    'PRESTOPAY_MID' => $_ENV['PRESTOPAY_MID'],
    'PRESTOPAY_PRIVATE_KEY_FILE' => $_ENV['PRESTOPAY_PRIVATE_KEY_FILE'],
    'PRESTOPAY_PUBLIC_KEY_FILE' => $_ENV['PRESTOPAY_PUBLIC_KEY_FILE'],
]);
```

Unlike the Java and Go SDKs' `fromEnv()`, this one takes an array rather than reading the process environment
itself — the SDK never calls `getenv()` or reads `$_ENV` on its own, so the caller stays in control of where
secrets come from.

| Key | Required | Description |
|-----|----------|-------------|
| `PRESTOPAY_ENV` | One of this or a base URL | `staging` or `production` |
| `PRESTOPAY_BASE_URL` | Alternative to `PRESTOPAY_ENV` | Overrides the gateway base URL |
| `PRESTOPAY_MID` | Yes | Merchant `mid` for this client |
| `PRESTOPAY_PRIVATE_KEY_FILE` | One of this or `PRESTOPAY_PRIVATE_KEY` | Path to a PEM private key |
| `PRESTOPAY_PRIVATE_KEY` | Alternative to the `_FILE` variant | PEM private key contents |
| `PRESTOPAY_PRIVATE_KEY_PASSWORD` | No | Private key passphrase |
| `PRESTOPAY_PUBLIC_KEY_FILE` | One of this or `PRESTOPAY_PUBLIC_KEY` | Presto's public key or certificate (PEM or DER) |
| `PRESTOPAY_PUBLIC_KEY` | Alternative to the `_FILE` variant | PEM public key/certificate contents |

For production, prefer loading keys from your secret store straight into the constructor rather than through
plain environment strings when possible.

## Retries and idempotency

`init`, `reverse` and `refund` are **not** automatically retried after the request may have reached Presto.
The default policy (`retryReads`, `initialBackoff`, `maxBackoff` constructor options) only ever retries `query`
and a write whose transport failure proves nothing was sent — and only the bundled cURL transport can prove
that; see [Custom HTTP client](#custom-http-client).

```php
use PrestoUniverse\PrestoPay\Exception\PrestoPayException;

try {
    $result = $presto->payments()->init($request);
} catch (PrestoPayException $error) {
    if ($error->mayHaveTakenEffect()) {
        // Query by $error->reconcileBy() before creating another payment.
    }
    throw $error;
}
```

`init` is idempotent by `txnRefNum` on the gateway side: resending it with an existing `txnRefNum` returns that
payment's current status rather than creating a second record or failing — this is gateway behaviour shared
across all four Presto Pay SDKs, confirmed against real staging traffic. `query` remains the more direct way to
check status without relying on that. See [payments and errors](docs/payments-and-errors.md).

## Webhooks

Presto POSTs JSON to your `notifyUrl` from its own infrastructure — the URL must be **publicly reachable**, not
`localhost`, unless you tunnel it. Verify the raw request body, confirm the merchant ID and timestamp, and
deduplicate by `eventRefNum` before fulfilment. A verifier needs only Presto's public certificate.

```php
use PrestoUniverse\PrestoPay\Key\PublicKey;
use PrestoUniverse\PrestoPay\Webhook\NotifyAck;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;

$verifier = new WebhookVerifier(
    [$_ENV['PRESTOPAY_MID']],                              // rejects events signed for other merchants
    [PublicKey::fromFile($_ENV['PRESTOPAY_PUBLIC_KEY_FILE'])],
);
try {
    $event = $verifier->verify((string) file_get_contents('php://input'));
    // Persist eventRefNum under a unique constraint, then handle the event once.
    $ack = NotifyAck::Ok;
} catch (\Throwable $error) {
    $ack = NotifyAck::forThrowable($error);
}
header('Content-Type: application/json');
echo $ack->body();
```

Rejects a webhook whose signed `ts` is more than 15 minutes from the local clock (`maxTimestampAge`
constructor option), so a captured webhook cannot be replayed later. Keep the host clock in sync (NTP).
`event->paymentStatus` reflects the notify contract. After any webhook, call `payments()->query()` for
authoritative payment status. See [webhook handling](docs/webhooks.md) and [production setup](docs/production.md).

## Errors

All SDK errors extend `PrestoPayException` (unchecked `\RuntimeException`), which exposes `operation()`,
`mayHaveTakenEffect()` and `reconcileBy()`:

| Type | When |
|------|------|
| `ApiException` | HTTP 200 with `success: false`, or a non-200 HTTP status. `errorCode()`, `errorMessage()`, `httpStatus()` |
| `SignatureException` | Invalid or missing signature on a response or webhook; webhook `mid` not configured, or `ts` outside the replay window |
| `ResponseException` | Response or webhook body cannot be parsed (malformed JSON, missing required fields). The operation may still have succeeded — reconcile with `query` |
| `TransportException` | Network, timeout, or TLS failure. `requestNotSent()` |
| `ConfigException` | Constructor or request validation, bad keys |

Compare business error codes to constants in `ErrorCode` (e.g. `ErrorCode::EXPIRED_TIMESTAMP` = `1005`, clock
skew — Presto timestamps use a fixed UTC+8 offset, so host clock accuracy matters).

## Custom HTTP client

Implement `HttpTransport` and pass it to the constructor (for proxies, pooling, or observability), or use the
bundled `Psr18Transport` to adapt any PSR-18 client:

```php
use PrestoUniverse\PrestoPay\Http\Psr18Transport;

$presto = new PrestoPay(
    environment: Environment::Staging,
    merchantId: 'YOUR_MID',
    privateKey: $privateKey,
    prestoPublicKeys: [$publicKey],
    transport: new Psr18Transport($guzzleClient, $requestFactory, $streamFactory),
);
```

A custom transport must return non-2xx responses rather than throw, must not follow redirects or resend
requests, and must set `requestNotSent` on `HttpFailure` only when the request certainly never left the
process. **An injected PSR-18 client cannot prove this** — `NetworkExceptionInterface` says only "the request
never completed", not "the request was never written" — so every injected-client transport failure is treated
as sent, and `init`/`reverse`/`refund` are never automatically retried behind one. Use the bundled
`CurlTransport` for the payment path if that automatic-retry-when-proven-unsent behaviour matters to you.

## Debugging signatures

Use `Canonicalizer::fromJson($json)` to reproduce the gateway canonical string from a raw JSON body. Avoid
depending on types under `PrestoUniverse\PrestoPay\Internal` — they are not semver-stable.

## Samples

- [sample/my-store/](sample/my-store/) — a runnable **MyStore**-branded checkout page against Presto staging,
  served by PHP's built-in web server with no framework and no dependencies beyond the SDK. A toggle switches
  between the Presto-hosted and self-hosted ways to pick a payment method; a return page and a webhook handler
  with a "recent webhooks" list round it out.

See [sample/README.md](sample/README.md) for details.

## Contributing

```bash
composer install
composer check   # phpunit + phpstan (max, strict rules) + php-cs-fixer
```

For an opt-in staging query smoke test, set `PRESTOPAY_STAGING_SMOKE=1` plus the credentials above,
`PRESTOPAY_MRN`, and `PRESTOPAY_STAGING_PAYMENT_REF_NUM`, then run `composer test`.

The `spec/` directory is a checked-in snapshot of the shared wire contract and test vectors. Its source commit
is recorded in [`spec/.source-commit`](spec/.source-commit). No real merchant or staging credentials belong in
this repository.

## License

Apache License 2.0 — see [LICENSE](LICENSE).
