# Production

- [Configuration from environment](#configuration-from-environment)
- [Keys](#keys)
- [Several merchants](#several-merchants)
- [Custom HTTP client](#custom-http-client)
- [Going live checklist](#going-live-checklist)
- [Troubleshooting](#troubleshooting)

## Configuration from environment

`PrestoPay::fromEnv()` builds a client from an array of settings. It never reads `getenv()` or `$_ENV` itself,
so you decide where the values come from:

```php
$presto = PrestoPay::fromEnv([
    'PRESTOPAY_ENV' => 'production',
    'PRESTOPAY_MID' => $_ENV['PRESTOPAY_MID'],
    'PRESTOPAY_PRIVATE_KEY_FILE' => $_ENV['PRESTOPAY_PRIVATE_KEY_FILE'],
    'PRESTOPAY_PUBLIC_KEY_FILE' => $_ENV['PRESTOPAY_PUBLIC_KEY_FILE'],
]);
```

| Key | Value |
|-----|-------|
| `PRESTOPAY_ENV` | `staging` or `production`; `staging` if left out |
| `PRESTOPAY_BASE_URL` | A gateway base URL, used instead of `PRESTOPAY_ENV` |
| `PRESTOPAY_MID` | Your `mid` |
| `PRESTOPAY_PRIVATE_KEY_FILE` or `PRESTOPAY_PRIVATE_KEY` | A path to your PEM private key, or its contents |
| `PRESTOPAY_PRIVATE_KEY_PASSWORD` | The key's password, if it's encrypted |
| `PRESTOPAY_PUBLIC_KEY_FILE` or `PRESTOPAY_PUBLIC_KEY` | A path to Presto's `.der` certificate, or its contents as PEM text |

`PRESTOPAY_PUBLIC_KEY` holds text, so it can't hold the binary `.der` file. To pass the certificate inline,
convert it to PEM once and use the contents of `presto.pem`:

```bash
openssl x509 -inform der -in presto.der -out presto.pem
```

## Keys

`PrivateKey::fromFile()` and `PrivateKey::fromPem()` read a PEM private key, including an encrypted one when you
pass its password. Keep the file outside the web root, readable only by the PHP process, and load it from your
secret store where you can.

`PublicKey::fromFile()` reads Presto's certificate as DER or PEM; `PublicKey::fromDer()` and
`PublicKey::fromPem()` take the contents directly. `prestoPublicKeys` is a list so that, when Presto announces a
new certificate, you can accept both the old and the new one until the change-over is done.

**Using an existing `.p12` keystore.** The SDK doesn't read PKCS#12 directly. If you already have your key in a
`.p12` file, convert it once:

```bash
openssl pkcs12 -in merchant.p12 -nocerts -nodes -out merchant-key.pem
```

Some keystores use an old encryption algorithm that OpenSSL 3 refuses: it prints an `unsupported` error and
exits with status 1, but only after writing the key. If `merchant-key.pem` contains a `BEGIN PRIVATE KEY` block,
the conversion worked.

## Several merchants

A `PrestoPay` client belongs to one `mid`: it sends that `mid` on every request. One `mid` can have several
`prestoMrn`s, which you choose per request, so one client covers all of them.

To serve several merchants, build one client per `mid` (they can share the same keys) and route each request to
the matching client, for example with an array keyed by `mid`. For webhooks, one verifier can accept all of
them; see [Webhooks](webhooks.md#several-merchants-and-webhook-only-services).

## Custom HTTP client

The SDK sends requests with cURL by default. To use another client, implement `HttpTransport`, or wrap any PSR-18
client with the bundled `Psr18Transport`:

```php
use PrestoUniverse\PrestoPay\Http\Psr18Transport;

$presto = new PrestoPay(
    environment: Environment::Production,
    merchantId: 'YOUR_MID',
    privateKey: $privateKey,
    prestoPublicKeys: [$prestoKey],
    transport: new Psr18Transport($guzzleClient, $requestFactory, $streamFactory),
);
```

A custom transport must return non-2xx responses rather than throw, and must not follow redirects or resend
requests. It may set `requestNotSent` on `HttpFailure` only when the request certainly never left the process.

A PSR-18 client can't tell whether a failed request was sent: its `NetworkExceptionInterface` means "never
completed", not "never written". So with `Psr18Transport` every transport failure counts as possibly sent, and
`init`, `reverse` and `refund` are never retried automatically. Use the default cURL transport for payments if
that matters to you. With a PSR-18 client, set its own timeout and turn off its redirects.

## Going live checklist

- [ ] Generate a separate key pair for production and register its public key with Presto.
- [ ] Use `Environment::Production` with your production `mid`, `prestoMrn` and Presto certificate. Never mix
      staging and production values.
- [ ] Keep the private key outside the repository and the web root, and load it from a secret store where you
      can.
- [ ] Make `notifyUrl` a public HTTPS URL that Presto can reach.
- [ ] Have your return page `query` the payment instead of trusting the redirect.
- [ ] Have your webhook handler verify the raw body, `query` the payment, apply its status with a guarded update that
      finalises an order only once and fulfils only on the change into `Authorised`, return 401 for a `SignatureException`, and reply `NotifyAck::Resend` when your own
      processing fails.
- [ ] After a timeout or server error, call `init` again with the same `txnRefNum`, and query before retrying
      `reverse` or `refund`, as in
      [Payments and errors](payments-and-errors.md#when-you-dont-know-whether-it-worked).
- [ ] Keep the server clock in sync with NTP.
- [ ] Log `errorCode()` and `errorMessage()` from `ApiException`, so you can quote them to Presto support.

## Troubleshooting

**`1005` (`ErrorCode::EXPIRED_TIMESTAMP`).** Your request's timestamp is too far from Presto's clock. Sync the
server clock with NTP. The SDK converts to the gateway's time zone itself, so the host's time zone doesn't
matter.

**`1006` or `1007` (`ErrorCode::INVALID_SIGNATURE`, `ErrorCode::SIGNATURE_VERIFICATION_FAILED`).** Presto
couldn't verify your signature. Usually the private key doesn't match the public key you registered for this
environment, or you're using a staging key in production or the other way round. To check what was signed,
rebuild the canonical string from a raw JSON body with `Canonicalizer::fromJson($json)`. Use that rather than the
classes under `PrestoUniverse\PrestoPay\Internal`, which aren't part of the public API.

**`SignatureException` from a payment call.** Presto's response didn't verify with the certificate you
configured. Check that it's the certificate for this environment, and whether Presto has announced a new one.

**`1102` or `1106`.** The `mid` or `prestoMrn` isn't valid for this environment.

**Webhooks never arrive.** `notifyUrl` must be reachable from the internet. `localhost` and private addresses
won't work; during development, use a tunnel such as ngrok and pass its URL as `notifyUrl`.

**Webhooks fail with `SignatureException`.** Either the event is for a `mid` the verifier wasn't given, its
timestamp is more than 15 minutes from your clock (sync with NTP), or the Presto certificate is for the wrong
environment.
