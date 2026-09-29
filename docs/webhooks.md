# Webhook handling

Read the raw HTTP body. In plain PHP use `file_get_contents('php://input')`; in Laravel or Symfony use
`$request->getContent()`; in PSR-7 handlers use `verifyServerRequest()` while the body stream is still readable.
The verifier checks the signature, required fields, configured merchant ID and a 15-minute timestamp window.

Persist `eventRefNum` with a unique database constraint before applying fulfilment effects. Presto can deliver
the same event again with a fresh `ts`; `eventRefNum` stays stable. Use `query` when you need the authoritative
payment state.

Return HTTP 200 with `NotifyAck::Ok->body()` (`{"resend":false}`) after successful processing. For a temporary
local failure, return `NotifyAck::Resend->body()` (`{"resend":true}`). Invalid signatures, foreign merchant IDs
and malformed or stale events are permanent failures; `NotifyAck::forThrowable()` maps these to `Ok` to avoid
repeated deliveries that cannot succeed.

A webhook-only process needs Presto's public certificate and merchant IDs. Keep the merchant private key out of
that process.

## Plain PHP

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
    $inserted = $pdo->prepare('INSERT INTO webhook_events (event_ref_num) VALUES (?) ON CONFLICT DO NOTHING')
        ->execute([$event->eventRefNum]);
    if ($inserted) {
        // Apply the fulfilment effect for $event exactly once.
    }
    $ack = NotifyAck::Ok;
} catch (\Throwable $error) {
    $ack = NotifyAck::forThrowable($error);
}

header('Content-Type: application/json');
echo $ack->body();
```

The unique constraint on `event_ref_num`, not the `if`, is what makes this safe under concurrent redeliveries.

## Laravel

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PrestoUniverse\PrestoPay\Webhook\NotifyAck;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;

class PrestoPayWebhookController
{
    public function __construct(private readonly WebhookVerifier $verifier) {}

    public function __invoke(Request $request)
    {
        try {
            $event = $this->verifier->verify($request->getContent());
            $isNew = DB::table('webhook_events')->insertOrIgnore(['event_ref_num' => $event->eventRefNum]) > 0;
            if ($isNew) {
                // Dispatch a job to apply the fulfilment effect for $event.
            }
            $ack = NotifyAck::Ok;
        } catch (\Throwable $error) {
            $ack = NotifyAck::forThrowable($error);
        }

        return response($ack->body(), 200)->header('Content-Type', 'application/json');
    }
}
```

Bind `WebhookVerifier` in a service provider using `PublicKey::fromFile` and your configured merchant IDs, and
never resolve `PrestoPay` (which needs the private key) inside a webhook-only route or queue worker.

## Symfony

```php
use Doctrine\DBAL\Connection;
use PrestoUniverse\PrestoPay\Webhook\NotifyAck;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PrestoPayWebhookController
{
    public function __construct(private readonly WebhookVerifier $verifier, private readonly Connection $db) {}

    #[Route('/presto/notify', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        try {
            $event = $this->verifier->verify($request->getContent());
            $rows = $this->db->executeStatement(
                'INSERT INTO webhook_events (event_ref_num) VALUES (?) ON CONFLICT DO NOTHING',
                [$event->eventRefNum],
            );
            if ($rows > 0) {
                // Apply the fulfilment effect for $event exactly once.
            }
            $ack = NotifyAck::Ok;
        } catch (\Throwable $error) {
            $ack = NotifyAck::forThrowable($error);
        }

        return new Response($ack->body(), 200, ['Content-Type' => 'application/json']);
    }
}
```

Register `WebhookVerifier` as a service with Presto's public certificate and your merchant IDs; a
webhook-only service definition needs no private key.

All three examples share the same shape: verify first, deduplicate on `eventRefNum` with a database-level
unique constraint (not an in-memory check, which does not survive redeploys or hold under concurrent
redeliveries), and let `NotifyAck::forThrowable()` decide the reply so a permanent failure — bad signature,
foreign `mid`, stale `ts` — is acknowledged instead of triggering four more redeliveries that would fail the
same way.
