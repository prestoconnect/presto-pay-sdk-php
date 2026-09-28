# Presto Pay SDK for PHP

A PHP 8.2+ merchant SDK for initiating, querying, reversing and requesting refunds for Presto Pay payments. This
repository is under implementation and has not been published to Packagist yet.

## Install

The planned package name is `prestouniverse/presto-pay-sdk`. After publication:

```bash
composer require prestouniverse/presto-pay-sdk
```

For development from this checkout, run `composer install` and include `vendor/autoload.php`.

The SDK requires 64-bit PHP, `ext-curl`, `ext-json` and `ext-openssl`. It runs on PHP 8.2–8.5 in CI.

## Credentials

Use the merchant ID, Presto merchant reference, your RSA private key in PEM format, and Presto's public
certificate supplied during onboarding. Store keys outside the repository and load paths or secret values from
your application's configuration. The SDK never reads environment variables by itself.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use PrestoUniverse\PrestoPay\PrestoPay;

$presto = PrestoPay::fromEnv([
    'PRESTOPAY_ENV' => 'staging',
    'PRESTOPAY_MID' => $_ENV['PRESTOPAY_MID'],
    'PRESTOPAY_PRIVATE_KEY_FILE' => $_ENV['PRESTOPAY_PRIVATE_KEY_FILE'],
    'PRESTOPAY_PUBLIC_KEY_FILE' => $_ENV['PRESTOPAY_PUBLIC_KEY_FILE'],
]);
```

The first release accepts PEM private keys. Direct `.p12` loading is pending compatibility checks across the
supported PHP runtimes.

## First payment

```php
use PrestoUniverse\PrestoPay\Request\InitRequest;
use PrestoUniverse\PrestoPay\TxnType;

$payment = $presto->payments()->init(new InitRequest(
    prestoMrn: $_ENV['PRESTOPAY_MRN'],
    txnType: TxnType::WebPay,
    txnRefNum: 'order-123',
    displayDesc: 'Order 123',
    amount: 1000, // minor currency units
    currencyCode: 'MYR',
    notifyUrl: 'https://your-store.example/presto/notify',
    redirectUrl: 'https://your-store.example/checkout/return',
));

header('Location: ' . $payment->paymentUrl, true, 302);
```

Use a unique `txnRefNum` for each order. Query the payment after an ambiguous failure and before fulfilment.
See [payments and errors](docs/payments-and-errors.md).

## Webhooks

Verify the raw request body, confirm the merchant ID and timestamp, and deduplicate by `eventRefNum` before
fulfilment. A verifier needs only Presto's public certificate.

```php
use PrestoUniverse\PrestoPay\Key\PublicKey;
use PrestoUniverse\PrestoPay\Webhook\NotifyAck;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;

$verifier = new WebhookVerifier(
    [$_ENV['PRESTOPAY_MID']],
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

See [webhook handling](docs/webhooks.md) and [production setup](docs/production.md).

## Development

```bash
composer install
composer check
```

For an opt-in staging query smoke test, set `PRESTOPAY_STAGING_SMOKE=1` plus the credentials above,
`PRESTOPAY_MRN`, and `PRESTOPAY_STAGING_PAYMENT_REF_NUM`, then run `composer test`.

The `spec/` directory is a checked-in snapshot of the shared wire contract and test vectors. Its source commit
is recorded in [`spec/.source-commit`](spec/.source-commit). No real merchant or staging credentials belong in
this repository.
