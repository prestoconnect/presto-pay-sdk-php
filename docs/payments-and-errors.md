# Payments and errors

`payments()` exposes `init`, `query`, `reverse` and `refund`. Requests are immutable value objects and use the
gateway's field names. Amounts are integer minor units. Payment methods and statuses are open-ended strings;
the provided constants cover known values but the gateway may add more.

```php
use PrestoUniverse\PrestoPay\Request\QueryRequest;
use PrestoUniverse\PrestoPay\Request\RefundRequest;

$current = $presto->payments()->query(new QueryRequest(
    prestoMrn: $prestoMrn,
    txnRefNum: 'order-123',
));

$refund = $presto->payments()->refund(new RefundRequest(
    prestoMrn: $prestoMrn,
    paymentRefNum: $current->paymentRefNum,
    refundRefNum: 'order-123-refund-1',
    remark: 'Customer request',
));
```

Any payment method can be submitted for refund. The gateway determines whether the refund succeeds; some
methods need manual or offline processing. A successful request response does not prove the refund is complete.
Query the payment and check its refund status before treating it as final.

For `init`, `reverse` and `refund`, a transport failure or signed-response failure may leave the operation's
state unknown. Catch `PrestoPayException`, inspect `mayHaveTakenEffect()`, and reconcile using `reconcileBy()`.
`init` uses `txnRefNum`; reverse and refund use `paymentRefNum`. A query failure is safe to retry. The SDK
automatically retries queries on transport failures and HTTP 5xx, within a whole-call deadline. It never
automatically retries writes after an ambiguous failure.

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

Business errors are `ApiException` and expose `errorCode()` and `errorMessage()`. HTTP errors also expose
`httpStatus()`. Exception messages omit gateway response bodies and private keys.
