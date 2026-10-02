# Presto Pay SDK for PHP

[![Packagist](https://img.shields.io/packagist/v/prestouniverse/presto-pay-sdk.svg)](https://packagist.org/packages/prestouniverse/presto-pay-sdk)
[![CI](https://github.com/prestoconnect/presto-pay-sdk-php/actions/workflows/ci.yml/badge.svg)](https://github.com/prestoconnect/presto-pay-sdk-php/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)

Accept payments through the **Presto Connect** payment gateway from any PHP application. The SDK signs every
request, verifies every response and webhook, and gives you typed requests and results, so you don't have to
handle the gateway's signature scheme yourself.

- **PHP 8.2+** (64-bit), with or without a framework
- Needs only `ext-curl`, `ext-json` and `ext-openssl`; bring your own PSR-18 client if you prefer
- Immutable `readonly` requests and results: build one `PrestoPay` and reuse it

## Contents

- [Install](#install)
- [Before you start](#before-you-start)
- [How a payment works](#how-a-payment-works)
- [Quick start](#quick-start)
- [Payment statuses](#payment-statuses)
- [Next steps](#next-steps)

## Install

```bash
composer require prestouniverse/presto-pay-sdk
```

## Before you start

### 1. Create your key pair

You sign every request with your own RSA private key, and Presto verifies it with the matching public key.
Generate the pair yourself with `openssl`; the private key never leaves your systems:

```bash
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out merchant-key.pem
openssl req -new -x509 -key merchant-key.pem -days 99999 -subj "/CN=Your Company" -outform DER -out merchant.der
```

`merchant-key.pem` is your private key in the PEM format the SDK reads; keep it secret, out of source control
and outside the web root. Send `merchant.der` (your public key, in the DER format Presto requires) to Presto.
The certificate is valid for 99999 days (until the year 2300), so you won't have to generate a new key pair
and register it with Presto again.

### 2. Get your details from Presto

| From Presto | What it is | Where it goes |
|-------------|------------|---------------|
| Merchant ID (`mid`) | Identifies your merchant account | `new PrestoPay(merchantId: ...)` |
| Presto merchant reference (`prestoMrn`) | Identifies the shop or outlet; one `mid` can have several | Every request: `prestoMrn` |
| Presto certificate (`.der`) | Verifies Presto's responses and webhooks; the SDK reads it as is | `PublicKey::fromFile(...)` |

Staging and production are separate: each has its own `mid`, `prestoMrn` and Presto certificate, and you
register your public key for each. Never mix them.

## How a payment works

```
 Your server                      Presto                     Shopper's browser
     |---- 1. init ------------------>|                              |
     |<--- paymentUrl ----------------|                              |
     |---- 2. redirect to paymentUrl ------------------------------->|
     |                                |<---- 3. shopper pays --------|
     |                                |---- 4a. redirect to your redirectUrl -->|
     |<--- 4b. webhook to your notifyUrl                             |
     |---- 5. query ----------------->|                              |
```

1. Your server calls `init` with your order's reference and amount. Presto returns a `paymentUrl`.
2. You redirect the shopper to `paymentUrl`.
3. The shopper chooses a payment method and pays on Presto's page.
4. Presto sends the shopper's browser back to your `redirectUrl` **and** POSTs a signed webhook to your
   `notifyUrl`. These happen independently and can arrive in either order.
5. On both, you call `query` to get the payment's status from Presto, and update the order.

The identifiers you'll see:

| Name | Who creates it | What it's for |
|------|----------------|---------------|
| `txnRefNum` | You | Your reference for the payment, such as an order ID. Unique per payment, at most 50 characters |
| `paymentRefNum` | Presto | Presto's reference for the payment, returned by `init` |
| `eventRefNum` | Presto | Identifies one webhook event; stays the same when Presto redelivers it |
| `reversalRefNum`, `refundRefNum` | You | Your reference for a reversal or a refund |

## Quick start

These examples are plain PHP. For Laravel and Symfony, see [Webhooks](docs/webhooks.md#laravel) and the
[samples](sample/README.md).

### 1. Create the client

Create it once and reuse it. Bad keys fail here, not on the first payment. The webhook verifier needs only
Presto's certificate and your `mid`.

```php
use PrestoUniverse\PrestoPay\Environment;
use PrestoUniverse\PrestoPay\Key\PrivateKey;
use PrestoUniverse\PrestoPay\Key\PublicKey;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;

$prestoKey = PublicKey::fromFile('/path/to/presto.der');

$presto = new PrestoPay(
    environment: Environment::Staging,
    merchantId: 'YOUR_MID',
    privateKey: PrivateKey::fromFile('/path/to/merchant-key.pem'),
    prestoPublicKeys: [$prestoKey],
);

$verifier = new WebhookVerifier(['YOUR_MID'], [$prestoKey]);
```

To configure the client from environment variables instead, see
[Configuration](docs/production.md#configuration-from-environment).

### 2. Start a payment

```php
use PrestoUniverse\PrestoPay\PaymentMethod;
use PrestoUniverse\PrestoPay\Request\InitRequest;
use PrestoUniverse\PrestoPay\TxnType;

$payment = $presto->payments()->init(new InitRequest(
    prestoMrn: 'YOUR_PRESTO_MRN',
    txnType: TxnType::WebPay,
    txnRefNum: $orderId,
    displayDesc: "Order {$orderId}",
    amount: 10_000, // minor units: MYR 100.00
    currencyCode: 'MYR',
    notifyUrl: 'https://your-app.example/presto/notify',
    redirectUrl: "https://your-app.example/presto/return/{$orderId}",
    allowedPaymentMethods: [PaymentMethod::PM_PG_CARD], // Skip this unless you build your own payment selection page
));

// Save $payment->paymentRefNum with the order, then send the shopper to Presto.
if ($payment->paymentUrl === null) {
    throw new \RuntimeException("Presto returned no paymentUrl for {$orderId}");
}
header('Location: ' . $payment->paymentUrl, true, 302);
```

`notifyUrl` must be reachable from the internet; on your own machine, use a tunnel such as ngrok. For the codes
you can pass to `allowedPaymentMethods`, see [Payment methods](docs/payment-methods.md).

### 3. Show the result on your return page

The redirect only tells you the shopper came back, not whether they paid. Ask Presto:

```php
use PrestoUniverse\PrestoPay\PaymentStatus;
use PrestoUniverse\PrestoPay\Request\QueryRequest;

$result = $presto->payments()->query(new QueryRequest(prestoMrn: 'YOUR_PRESTO_MRN', txnRefNum: $orderId));

if ($result->paymentStatus === PaymentStatus::AUTHORISED) {
    // Paid: show the confirmation.
} elseif ($result->paymentStatus === PaymentStatus::PENDING_AUTHORISE) {
    // Not finished yet: show "processing" and check again shortly.
} else {
    // Not paid (Failed, Cancelled, Expired, ...).
}
```

### 4. Handle the webhook

A webhook tells you something happened to a payment (`eventCode`, and `success` for whether it worked), not the
payment's resulting status, so query for that here too. Verify the **raw** request body, exactly as received.

```php
use PrestoUniverse\PrestoPay\Exception\SignatureException;
use PrestoUniverse\PrestoPay\Webhook\NotifyAck;

try {
    $event = $verifier->verify((string) file_get_contents('php://input'));
} catch (SignatureException $error) {
    http_response_code(401); // forged, for another mid, or too old
    return;
} catch (\Throwable $error) {
    header('Content-Type: application/json');
    echo NotifyAck::forThrowable($error)->body(); // malformed body
    return;
}

header('Content-Type: application/json');
try {
    $payment = $presto->payments()->query(new QueryRequest(
        prestoMrn: $event->prestoMrn,
        paymentRefNum: $event->paymentRefNum,
    ));
    $orders->applyStatus($event->txnRefNum, $payment->paymentStatus);
} catch (\Throwable $error) {
    echo NotifyAck::Resend->body();
    return;
}
echo NotifyAck::Ok->body();
```

`NotifyAck::Ok` tells Presto the event is handled. `NotifyAck::Resend` asks Presto to deliver it again, which you
want when your own processing failed. Presto resends with a backoff of 2, 4, 8, 16, 32, 64, 128, 256, 512 and 1024
minutes between attempts.

The same event can arrive more than once, so `applyStatus` checks the order, not the event: it finalises the
order only if the order hasn't been finalised yet, and fulfils only on the change into `Authorised`. A
redelivery then finds the order already in that status and changes nothing. See
[Webhooks](docs/webhooks.md#handling-redeliveries) for the details.

Update the order the same way from your return page and your webhook: whichever arrives first records the
status, and the other finds it already done.

## Payment statuses

`paymentStatus` is one of these strings; compare it with the `PaymentStatus` constants.

| Status | Meaning | What to do |
|--------|---------|------------|
| `PendingAuthorise` | Created; the shopper hasn't finished paying | Wait. It becomes `Expired` if not paid within 15 minutes of `init` |
| `Authorised` | Paid | Fulfil the order |
| `Failed` | The payment attempt failed | Don't fulfil |
| `Cancelled` | Cancelled before it was paid, for example by `reverse` | Don't fulfil |
| `Expired` | Not paid within 15 minutes | Don't fulfil; start a new payment if the shopper returns |
| `PendingReverse` | A reversal is in progress | Query again later |
| `Reversed` | The payment was reversed | Treat the order as cancelled |
| `PendingRefund` | A refund is in progress | Query again later |
| `PartialRefunded` | Part of the amount was refunded | Update the order's refunded amount |
| `Refunded` | The full amount was refunded | Treat the order as refunded |

The gateway can add statuses, so handle an unknown value without failing.

## Next steps

- [Payment methods](docs/payment-methods.md): every payment method code, which ones you can use, and passing a
  code the SDK doesn't list yet.
- [Payments and errors](docs/payments-and-errors.md): query, reverse and refund payments; handle errors and
  timeouts safely.
- [Webhooks](docs/webhooks.md): replies, redelivery, guarding the order update, and complete Laravel and Symfony handlers.
- [Production](docs/production.md): configuration, keys, several merchants, custom HTTP clients, the go-live
  checklist and troubleshooting.
- [Samples](sample/README.md): the same checkout, runnable against Presto staging, in plain PHP
  ([`my-store`](sample/my-store/)), Laravel ([`laravel-store`](sample/laravel-store/)) and Symfony
  ([`symfony-store`](sample/symfony-store/)).

## Contributing

Building, testing, code style and the release process are in [CONTRIBUTING.md](CONTRIBUTING.md). Report
security issues as described in [SECURITY.md](SECURITY.md), not in a public issue. Real merchant or staging
credentials never belong in this repository.

## License

Apache License 2.0. See [LICENSE](LICENSE).
