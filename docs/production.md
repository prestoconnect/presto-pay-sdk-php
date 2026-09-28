# Production setup

Use the production endpoint only with production merchant credentials. Keep PEM private keys and passwords in
your secret store, outside the repository and web root. Restrict file permissions and rotate keys when Presto
announces a rotation. The client accepts multiple Presto public keys so old and new signatures can overlap.

Keep server time accurate. Every request uses a timestamp at the fixed UTC+08:00 offset and the gateway rejects
timestamps outside its validity window. Configure a whole-call timeout appropriate for your application. The
bundled cURL transport rejects redirects. If you inject a PSR-18 client, configure its own timeout and redirect
policy: PSR-18 does not expose whether request bytes were sent, so write failures remain ambiguous.

Persist payment references and webhook deduplication keys. After a payment or refund timeout, query the
transaction before deciding whether to retry manually. A refund request can require manual or offline
processing depending on payment method. Do not treat request acceptance as completion.

Staging smoke tests should be opt-in and use credentials loaded from your secret store. Never commit live
merchant credentials, onboarding keystores, converted private keys or captured customer data.
