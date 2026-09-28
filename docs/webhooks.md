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
