# Webhooks

Presto POSTs a signed JSON webhook to the `notifyUrl` you pass to `init`, `reverse` or `refund` when something
happens to that payment. The [quick start](../README.md#4-handle-the-webhook) has a plain PHP handler; this
guide explains each part, with complete Laravel and Symfony handlers at the end.

- [What a webhook tells you](#what-a-webhook-tells-you)
- [Verifying it](#verifying-it)
- [Replying](#replying)
- [Handling redeliveries](#handling-redeliveries)
- [The freshness window](#the-freshness-window)
- [Several merchants, and webhook-only services](#several-merchants-and-webhook-only-services)
- [Laravel](#laravel)
- [Symfony](#symfony)

## What a webhook tells you

`$verifier->verify(...)` returns a `WebhookEvent`:

| Property | Value |
|----------|-------|
| `eventCode` | What happened: `Authorised`, `Cancelled`, `Reversed`, `Refunded` or `Expired`. Presto may add codes |
| `success` | Whether it worked. A `Refunded` event with `success` false is a refund that failed |
| `txnRefNum`, `paymentRefNum`, `prestoMrn`, `mid` | Which payment it's about |
| `eventRefNum` | Identifies this event; the same on every redelivery |
| `amount`, `currencyCode`, `paymentDetails` | The payment's amount and how it was paid |

A webhook reports an event, not the payment's resulting status. A failed refund, for example, leaves the
payment in whatever status it had before, which the event doesn't carry. To act on a webhook, `query` the
payment and use the status it returns.

## Verifying it

The verifier checks that:

- the body is signed by Presto,
- the required fields are present,
- the event is for one of your merchant IDs, and
- its timestamp is within 15 minutes of your clock.

The `mid` check matters: Presto signs webhooks for every merchant with the same key, so a genuine webhook for
someone else's account would otherwise pass.

Pass the **raw** body, exactly as received: `file_get_contents('php://input')` in plain PHP, and
`$request->getContent()` in Laravel or Symfony. In a PSR-7 handler, call `$verifier->verifyServerRequest($request)`
while the body stream is still unread. JSON you've decoded and encoded again won't verify.

A failure throws `SignatureException` (bad signature, another merchant's `mid`, or a stale timestamp) or
`ResponseException` (malformed body). Answer a `SignatureException` with HTTP 401. For a malformed body,
`NotifyAck::forThrowable($error)` gives `{"resend":false}`, since a redelivery would fail the same way.

## Replying

Reply HTTP 200 with a JSON body:

| Body | Meaning | When to send it |
|------|---------|-----------------|
| `NotifyAck::Ok->body()` (`{"resend":false}`) | Handled; don't send it again | You've recorded the event, or had already recorded it earlier |
| `NotifyAck::Resend->body()` (`{"resend":true}`) | Send it again later | Your own processing failed, for example the `query` or your database |

Presto retries 1, 2, 5 and 10 minutes after the first attempt, so an event is delivered at most five times over
about 18 minutes. Only ask for a resend when trying again could succeed.

`NotifyAck::forThrowable($error)` picks the reply for an exception: `Ok` for a webhook that failed verification,
and `Resend` for anything else, including a failed `query` inside your handler.

Reply quickly. Record the event and reply, and do slow work such as emails or fulfilment in a queued job.

## Handling redeliveries

The same event can arrive more than once, for example after you ask for a resend. Every delivery of an event
has the same `eventRefNum`, so:

- record `eventRefNum` under a unique constraint in your database once you've handled the event;
- skip events you've already recorded, and still reply `NotifyAck::Ok`;
- record it only after the `query` succeeds, so a failed attempt isn't mistaken for a handled one on
  redelivery.

The unique constraint, not an `if` in your code or an in-memory set, is what keeps this safe when two deliveries
arrive at once or your app is redeployed. Record the event and update the order in one transaction, so a
failure in between can't leave the event recorded but the order unchanged. Keep recorded `eventRefNum`s for at least as long as the redelivery
schedule (about 18 minutes).

## The freshness window

The verifier rejects a webhook whose timestamp is more than 15 minutes from your clock, so a captured webhook
can't be replayed later. Each redelivery carries a fresh timestamp, so redeliveries pass. Keep your server's
clock in sync with NTP.

To change the window, pass `maxTimestampAge` (in seconds) to `WebhookVerifier`. Widen it only if you
deduplicate on `eventRefNum`, since that becomes your protection against replays.

## Several merchants, and webhook-only services

`WebhookVerifier` takes a list of merchant IDs, so one endpoint can serve several merchants. Use `$event->mid`
to pick the matching `PrestoPay` client before you query.

A verifier holds no private key. Bind it in your container with Presto's certificate and your merchant IDs, and
keep `PrestoPay` (which needs the private key) out of a webhook-only process or queue worker.

## Laravel

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\QueryRequest;
use PrestoUniverse\PrestoPay\Exception\SignatureException;
use PrestoUniverse\PrestoPay\Webhook\NotifyAck;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;

class PrestoPayWebhookController
{
    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly PrestoPay $presto,
    ) {}

    public function __invoke(Request $request)
    {
        try {
            $event = $this->verifier->verify($request->getContent());
        } catch (SignatureException $error) {
            return response('', 401);
        } catch (\Throwable $error) {
            return response(NotifyAck::forThrowable($error)->body(), 200)->header('Content-Type', 'application/json');
        }

        try {
            $payment = $this->presto->payments()->query(new QueryRequest(
                prestoMrn: $event->prestoMrn,
                paymentRefNum: $event->paymentRefNum,
            ));
            DB::transaction(function () use ($event, $payment) {
                if (DB::table('webhook_events')->insertOrIgnore(['event_ref_num' => $event->eventRefNum]) > 0) {
                    // Update the order from $payment->paymentStatus, and dispatch any slow work as a job.
                }
            });
            $ack = NotifyAck::Ok;
        } catch (\Throwable $error) {
            $ack = NotifyAck::Resend;
        }

        return response($ack->body(), 200)->header('Content-Type', 'application/json');
    }
}
```

Bind `WebhookVerifier` and `PrestoPay` in a service provider. [`sample/laravel-store`](../sample/laravel-store/)
shows the whole setup.

## Symfony

```php
use Doctrine\DBAL\Connection;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\QueryRequest;
use PrestoUniverse\PrestoPay\Exception\SignatureException;
use PrestoUniverse\PrestoPay\Webhook\NotifyAck;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PrestoPayWebhookController
{
    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly PrestoPay $presto,
        private readonly Connection $db,
    ) {}

    #[Route('/presto/notify', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        try {
            $event = $this->verifier->verify($request->getContent());
        } catch (SignatureException $error) {
            return new Response('', 401);
        } catch (\Throwable $error) {
            return new Response(NotifyAck::forThrowable($error)->body(), 200, ['Content-Type' => 'application/json']);
        }

        try {
            $payment = $this->presto->payments()->query(new QueryRequest(
                prestoMrn: $event->prestoMrn,
                paymentRefNum: $event->paymentRefNum,
            ));
            $this->db->transactional(function (Connection $db) use ($event, $payment): void {
                $rows = $db->executeStatement(
                    'INSERT INTO webhook_events (event_ref_num) VALUES (?) ON CONFLICT DO NOTHING',
                    [$event->eventRefNum],
                );
                if ($rows > 0) {
                    // Update the order from $payment->paymentStatus.
                }
            });
            $ack = NotifyAck::Ok;
        } catch (\Throwable $error) {
            $ack = NotifyAck::Resend;
        }

        return new Response($ack->body(), 200, ['Content-Type' => 'application/json']);
    }
}
```

Register `WebhookVerifier` and `PrestoPay` as services. [`sample/symfony-store`](../sample/symfony-store/) shows
the whole setup.
