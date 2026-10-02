# Payments and errors

This guide covers the four payment operations and how to handle their failures. It assumes you've set up a
client as in the [quick start](../README.md#quick-start).

- [Query a payment](#query-a-payment)
- [Reverse a payment](#reverse-a-payment)
- [Refund a payment](#refund-a-payment)
- [Errors](#errors)
- [When you don't know whether it worked](#when-you-dont-know-whether-it-worked)
- [Retries and deadlines](#retries-and-deadlines)

Every request needs your `prestoMrn`. Requests are immutable objects that use the gateway's field names, and
amounts are integers in minor units (`10_000` is MYR 100.00). A missing or invalid field throws
`ConfigException` before anything is sent.

## Query a payment

`query` returns a payment's current status and details. Look it up by your `txnRefNum` or by Presto's
`paymentRefNum`:

```php
use PrestoUniverse\PrestoPay\Request\QueryRequest;

$payment = $presto->payments()->query(new QueryRequest(
    prestoMrn: 'YOUR_PRESTO_MRN',
    txnRefNum: 'order-123', // or paymentRefNum:
));

$payment->paymentStatus;  // see the status table in the README
$payment->reversalStatus; // Reversing, Failed or Success, once you've requested a reversal
$payment->refundStatus;   // Refunding, Failed or Success, once you've requested a refund
$payment->refundDetails;  // one entry per refund
```

`query` only reads, so it is always safe to call again.

## Reverse a payment

`reverse` undoes a whole payment:

```php
use PrestoUniverse\PrestoPay\Request\ReverseRequest;

$reversal = $presto->payments()->reverse(new ReverseRequest(
    prestoMrn: 'YOUR_PRESTO_MRN',
    reversalRefNum: 'rev-order-123', // your reference for this reversal, at most 50 characters
    paymentRefNum: $paymentRefNum,   // or txnRefNum:
    remark: 'Customer cancelled',    // optional
    notifyUrl: 'https://your-app.example/presto/notify', // optional: get a Reversed webhook
));
```

What happens depends on the payment's status:

- **`PendingAuthorise`** (not paid yet): the payment is cancelled and its status becomes `Cancelled`.
- **`Expired`**: fails with error `1219` (`ErrorCode::INVALID_REVERSAL_STATUS`). There's nothing left to undo.
- **Paid**: the gateway decides. It can refuse once the payment has settled (`1220`) or its reversal window
  has passed (`1221`). Use `refund` instead in that case.

## Refund a payment

`refund` returns all or part of a paid payment's amount:

```php
use PrestoUniverse\PrestoPay\Request\RefundRequest;

$refund = $presto->payments()->refund(new RefundRequest(
    prestoMrn: 'YOUR_PRESTO_MRN',
    paymentRefNum: $paymentRefNum,
    refundRefNum: 'ref-order-123', // your reference for this refund, at most 50 characters
    remark: 'Item out of stock',   // required, at most 200 characters
    amount: 2_500,                 // optional: leave it out to refund the full amount
    notifyUrl: 'https://your-app.example/presto/notify', // optional: get a Refunded webhook
));
```

You can request a refund for any payment method, but whether it succeeds depends on the method; some need
manual or offline processing by Presto. A successful `refund` call means Presto accepted the request, not that
the money has moved. Check `refundStatus` with `query`, or wait for the `Refunded` webhook, before treating the
refund as complete. A refund on an unpaid (`PendingAuthorise`) payment fails with `1227`
(`ErrorCode::INVALID_REFUND_STATUS`); use `reverse` to cancel it instead.

## Errors

Every SDK error extends `PrestoPayException` (an unchecked `\RuntimeException`), which has `operation()`,
`mayHaveTakenEffect()` and `reconcileBy()`:

| Exception | When | Useful methods |
|-----------|------|----------------|
| `ConfigException` | Invalid constructor options or request input, or a key that can't be loaded | |
| `TransportException` | Network failure, timeout or TLS error | `requestNotSent()` |
| `ApiException` | Presto rejected the request: a non-200 status, or HTTP 200 with `success: false` | `errorCode()`, `errorMessage()`, `httpStatus()` |
| `SignatureException` | A response or webhook signature is missing or invalid, a webhook is for another `mid`, or a webhook is too old | |
| `ResponseException` | A response or webhook body can't be parsed or is missing a field | |

Compare `errorCode()` with the `ErrorCode` constants, for example
`$error->errorCode() === ErrorCode::PAYMENT_NOT_FOUND`. For codes that point at your setup (`1005`, `1006`,
`1007`), see [Troubleshooting](production.md#troubleshooting). Exception messages never include gateway
response bodies or keys.

## When you don't know whether it worked

A timeout, a server error (HTTP 5xx) or a garbled response leaves you not knowing whether Presto acted on your
request. Every error tells you this directly: `mayHaveTakenEffect()` is true when the request may have reached
Presto and been acted on.

**`init`: call it again with the same `txnRefNum`.** That's safe. If the first call reached Presto, you get the
existing payment and its current status back rather than a second payment:

```php
use PrestoUniverse\PrestoPay\Exception\PrestoPayException;

try {
    $payment = $presto->payments()->init($request);
} catch (PrestoPayException $error) {
    if (!$error->mayHaveTakenEffect()) {
        throw $error;
    }
    $payment = $presto->payments()->init($request); // same txnRefNum: returns the payment if it was created
}
```

If Presto answers `1203` (`ErrorCode::DUPLICATE_TXN_REF_NUM`), a payment with that `txnRefNum` exists; `query`
it to find its state.

**`reverse` and `refund`: check before trying again.** Sending one of these twice could reverse or refund
twice, so `query` first, and look at `reversalStatus` or `refundStatus`. Try again only if the first request
didn't take effect. `reconcileBy()` holds the `paymentRefNum` to query with:

```php
$current = $presto->payments()->query(new QueryRequest(
    prestoMrn: 'YOUR_PRESTO_MRN',
    paymentRefNum: $error->reconcileBy(),
));
```

## Retries and deadlines

The client retries for you only when it's safe:

- **`query`**: on network errors and HTTP 5xx responses, honouring `Retry-After`.
- **`init`, `reverse`, `refund`**: only when the request certainly never left your machine
  (`requestNotSent()` is true). Only the bundled cURL transport can tell; see
  [Custom HTTP client](production.md#custom-http-client).

By default it retries twice, backing off from 0.2 seconds up to 2 seconds, and gives each call 30 seconds in
total, retries included. To change them, pass constructor options:

```php
$presto = new PrestoPay(
    // ...
    deadline: 20.0,
    retryReads: 3,
    initialBackoff: 0.5,
    maxBackoff: 5.0,
);
```

For a gateway operation the SDK doesn't wrap yet, `$presto->raw()->post('/path', $body)` signs, sends, verifies
and returns the parsed response.
