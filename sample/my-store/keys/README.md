# Staging signing keys

Copy these two files here from your Presto onboarding pack:

| File | Purpose |
|------|---------|
| Your merchant private key (PEM) | Request signing. `PRESTOPAY_PRIVATE_KEY_FILE` |
| Presto's staging public key (DER or PEM) | Response and webhook signature verification. `PRESTOPAY_PUBLIC_KEY_FILE` |

Point `.env` (see [`../.env.example`](../.env.example)) at wherever you put them — they do not have to live in
this directory, and nothing under `keys/` is committed to this repository.
