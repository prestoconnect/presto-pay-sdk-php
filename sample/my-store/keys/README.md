# Staging signing keys

Put these two files here:

| File | Where it comes from | Purpose |
|------|---------------------|---------|
| Your merchant private key (PEM) | The key pair you generated and registered with Presto for staging; see [Create your key pair](../../../README.md#1-create-your-key-pair) | Request signing. `PRESTOPAY_PRIVATE_KEY_FILE` |
| Presto's staging certificate (`.der`) | Presto | Response and webhook signature verification. `PRESTOPAY_PUBLIC_KEY_FILE` |

Point `.env` (see [`../.env.example`](../.env.example)) at wherever you put them — they do not have to live in
this directory, and nothing under `keys/` is committed to this repository.
