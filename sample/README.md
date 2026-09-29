# Samples

## MyStore

[`my-store/`](my-store/) — a **MyStore**-branded checkout page against **Presto staging**, styled with
Tailwind CSS (CDN) and Font Awesome icons, served by PHP's own built-in web server with no framework and no
third-party dependencies beyond the SDK itself.

### Staging credentials

Use the staging merchant credentials from your Presto onboarding pack. Nothing secret ships with the sample.

1. Copy your private key and Presto's public key somewhere on disk — see
   [`my-store/keys/README.md`](my-store/keys/README.md). They do not need to live inside this repository.
2. Copy [`my-store/.env.example`](my-store/.env.example) to `my-store/.env` (gitignored) and fill in the
   values.

Any value in `.env` can also come from a real exported environment variable, which always wins over `.env`.

### Checkout UI

One page ([`/`](http://localhost:8080/)) with a **"Show payment methods on checkout"** toggle that switches
between the two ways to call `init`, both driven by the page's own JavaScript calling a JSON `POST /checkout`
(not a browser form post):

| Toggle | Experience | SDK |
|--------|------------|-----|
| Off (default) | Amount + description → Presto hosted page | `init` without `allowedPaymentMethods` |
| On | Payment method list shown on this page first | `init` with `allowedPaymentMethods` |

`POST /checkout` accepts a JSON body and returns:

- `200` `{"paymentUrl": "...", "txnRefNum": "..."}` on success — the page redirects the browser to
  `paymentUrl` (or to `/return/{txnRefNum}` if Presto returned none)
- `400` with a field-name → message map on validation failure (e.g. `{"amountInRinggit": "Amount must be at
  least 0.01"}`)
- `502` with `{"message": "...", "mayHaveTakenEffect": bool, "reconcileBy": "..."}` on a gateway failure
  (signature, transport, or API error)

`GET /return/{txnRefNum}` shows the payment result (queries `payments()->query()` for authoritative status).

Also exposed, for `curl` and matching the Go SDK's sample: `GET /payments/{paymentRefNum}`,
`POST /payments/{paymentRefNum}/reverse`, `POST /payments/{paymentRefNum}/refund`.

### Source layout

```
sample/my-store/
├── public/
│   ├── index.php        Front controller: routes, validation, the four payment endpoints, webhook handler
│   └── js/checkout.js    Toggle behaviour and the JSON POST /checkout submit flow
├── src/
│   ├── AppConfig.php     .env loading, PrestoPay + WebhookVerifier construction
│   ├── ActivityStore.php Recent-checkouts / recent-webhooks storage (file-backed — see below)
│   └── Money.php         Ringgit ↔ sen conversion, demo txnRefNum generation
└── templates/
    ├── index.php          Checkout page
    └── return.php         Payment result page
```

### Why a file, not an in-memory store

The Go and Java samples keep recent activity in an in-process map: their server is one long-lived process, so
that state survives between requests. PHP does not work that way — the built-in server (like any other SAPI)
tears down all script-level state at the end of every request, so nothing declared in `index.php` or `AppConfig`
is still there on the next one. `ActivityStore` keeps the same "recent checkouts, recent webhooks, dedupe by
`eventRefNum`" behaviour by reading and writing a small JSON file under `var/` instead, with `flock()` guarding
concurrent requests. A real merchant would use its database's own tables and a unique constraint on
`eventRefNum` — see [`../docs/webhooks.md`](../docs/webhooks.md) — not a file; this is a demo-sized stand-in for
the same shape of state.

### Requirements

- **PHP 8.2+** (aligned with `presto-pay-sdk`), with `ext-curl`, `ext-json`, `ext-openssl`
- `composer install` run once at the repository root (this sample loads the SDK's own `vendor/autoload.php`
  and is autoloaded via the root `composer.json`'s `autoload-dev`)

### Run

```bash
composer install   # repo root
cd sample/my-store
cp .env.example .env   # then fill in your staging credentials and key paths
php -S localhost:8080 -t public public/index.php
```

Open [http://localhost:8080](http://localhost:8080).

### Webhooks (`notifyUrl`)

Each payment `init` sends `notifyUrl` = `{PUBLIC_URL}/presto/notify`. Presto's servers POST async events to
that URL from **outside your network**. If the URL is not publicly reachable (for example
`http://localhost:8080/...`), **webhooks will not arrive** — payments may still complete, but this demo's
"Recent webhooks" list and any server-side logic driven by notify will stay empty.

Use a tunnel (for example `ngrok http 8080`) and set `PUBLIC_URL` in `.env` to that origin (HTTPS
recommended) before starting checkouts. The payer `redirectUrl` uses the same base, so return links work in
the browser too.

Each `init` sets `redirectUrl` to `{PUBLIC_URL}/return/{txnRefNum}`. The `/return/{txnRefNum}` handler
**requires** that path segment; bare `/return` shows an explanatory error page instead of a stack trace.

`/presto/notify` verifies the signature, dedupes on `eventRefNum` (`ActivityStore::recordWebhook`), and answers
with `NotifyAck::Ok` for a verified event **and** for a permanent failure (bad signature, foreign `mid`, stale
`ts`) — never `NotifyAck::Resend` for those, since Presto would just redeliver the same unfixable body four
more times.
