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
public/
├── index.php         Front controller: routes, validation, the four payment endpoints, webhook handler
└── js/checkout.js    Toggle behaviour and the JSON POST /checkout submit flow
src/
├── AppConfig.php      .env loading, PrestoPay + WebhookVerifier construction
├── ActivityStore.php  Recent checkouts / recent webhooks, kept in a JSON file under var/ rather than an
│                      in-process map — a PHP request does not share memory with the next one, unlike the
│                      Go and Java samples' long-lived server process
└── Money.php          Ringgit ↔ sen conversion, demo txnRefNum generation
templates/
├── index.php    Checkout page
└── return.php   Payment result page
```

### Requirements

- **PHP 8.2+** (aligned with `presto-pay-sdk`), with `ext-curl`, `ext-json`, `ext-openssl`
- `composer install` at the repo root to build the SDK this sample depends on

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

## Laravel

[`laravel-store/`](laravel-store/) — the same MyStore checkout, routes, and webhook handling as above, rebuilt
as a Laravel 13 application: Blade views instead of plain PHP templates, a `PrestoPayServiceProvider` binding
`PrestoPay` and `WebhookVerifier` as singletons from [`config/prestopay.php`](laravel-store/config/prestopay.php),
and Laravel's own `Cache` facade (the `database` driver by default) standing in for `my-store/`'s JSON-file
`ActivityStore` — a real store, not a demo-only stand-in, so this one needs no rationale comment.

`/checkout`, `/payments/*/reverse` and `/payments/*/refund` are excluded from CSRF verification in
[`bootstrap/app.php`](laravel-store/bootstrap/app.php) so they stay JSON endpoints the checkout page's `fetch()`
call and `curl` can both hit directly, the same reason `/presto/notify` is excluded — Presto's servers cannot
hold a Laravel session either. A production app would instead have its page read a CSRF meta tag and send it
as a header on the `fetch()` call.

```bash
cd sample/laravel-store
composer install   # pulls prestouniverse/presto-pay-sdk from Packagist, like any other dependency
cp .env.example .env
php artisan key:generate
touch database/database.sqlite && php artisan migrate
# then edit .env: PRESTOPAY_MID, PRESTOPAY_MRN, PRESTOPAY_PRIVATE_KEY_FILE, PRESTOPAY_PUBLIC_KEY_FILE, PUBLIC_URL
php artisan serve
```

Open [http://localhost:8000](http://localhost:8000).

## Symfony

[`symfony-store/`](symfony-store/) — the same MyStore checkout, routes, and webhook handling again, rebuilt as
a Symfony 7.4 application: Twig templates, attribute-based routing (`#[Route]`), a `PrestoPayFactory` service
built from `%env(...)%`-bound constructor arguments in
[`config/services.yaml`](symfony-store/config/services.yaml), and the `cache.app` pool (filesystem by default)
standing in for `my-store/`'s JSON-file `ActivityStore`.

Credentials go in `.env.local` (gitignored), not `.env` — the committed `.env` documents the variable names
with empty defaults, matching how Symfony itself separates non-secret defaults from local overrides.

```bash
cd sample/symfony-store
composer install   # pulls prestouniverse/presto-pay-sdk from Packagist, like any other dependency
cat > .env.local <<'EOF'
APP_SECRET=change-me
PUBLIC_URL=http://localhost:8000
PRESTOPAY_MID=your-staging-merchant-id
PRESTOPAY_MRN=your-staging-presto-mrn
PRESTOPAY_PRIVATE_KEY_FILE=/absolute/path/to/merchant-private-key.pem
PRESTOPAY_PUBLIC_KEY_FILE=/absolute/path/to/presto-public-key.der
EOF
php -S localhost:8000 -t public public/index.php
```

Open [http://localhost:8000](http://localhost:8000).

## Shared UI

All three samples serve the exact same [`checkout.js`](my-store/public/js/checkout.js) and the same
Tailwind/Font Awesome markup — only the templating language and the file's own path comment differ. The JSON
contract for `POST /checkout` (and the curl-friendly `/payments/*` routes) is identical across all three, so
comparing any two side by side is really a comparison of how each framework wires config, routing, templating
and shared storage around the same SDK calls.
