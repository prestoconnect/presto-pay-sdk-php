# Contributing

Thanks for looking at `presto-pay-sdk-php`. This covers building, testing, and releasing the library itself —
for how to *use* the SDK in your own project, see [README.md](README.md).

## Building and testing

```bash
composer install
composer check   # phpunit + phpstan (max, strict rules) + php-cs-fixer
```

CI (`.github/workflows/ci.yml`) runs this on PHP 8.2, 8.3, 8.4, and 8.5.

Optional live staging smoke test (requires real staging credentials):

```bash
PRESTOPAY_STAGING_SMOKE=1 PRESTOPAY_MID=... PRESTOPAY_MRN=... \
  PRESTOPAY_PRIVATE_KEY_FILE=... PRESTOPAY_PUBLIC_KEY_FILE=... \
  PRESTOPAY_STAGING_PAYMENT_REF_NUM=... composer test
```

See `tests/StagingSmokeTest.php`'s own doc comment for what it checks.

## Code style

- PHP 8.2+ language level.
- Zero new runtime Composer dependencies beyond `psr/http-client`, `psr/http-factory`, and `psr/http-message`
  unless explicitly agreed — the product goal is close to zero-deps. The samples under `sample/` are separate
  projects (each with their own `composer.json`) and may take real framework dependencies.
- Match existing naming and error-handling patterns in `src/`.
- No code comments unless the *why* is genuinely non-obvious (a hidden constraint, a subtle invariant, a
  workaround for a specific bug) — see the existing source for the house style.
- `composer check` must be clean; fix findings rather than suppressing them where reasonable.

## Releasing

1. Set `SdkVersion::CURRENT` in `src/Internal/SdkVersion.php` to the release version (no `-dev` suffix) and
   move the CHANGELOG `Unreleased` entries under that version.
2. Commit, then push a matching tag, e.g. `git tag v0.1.0 && git push origin v0.1.0`.
3. Publish the tag to [Packagist](https://packagist.org/packages/prestouniverse/presto-pay-sdk) (first release
   only: submit the GitHub repository URL; afterwards Packagist's GitHub webhook picks up new tags
   automatically).
4. Bump `SdkVersion::CURRENT` back to the next `-dev` suffix, e.g. `0.2.0-dev` after releasing `v0.1.0`.
