<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Internal;

/**
 * Single source of truth for the SDK version, sent in the User-Agent header. Not public API; use
 * `PrestoPay::VERSION` instead.
 */
final class SdkVersion
{
    public const CURRENT = '0.1.0-dev';

    private function __construct() {}
}
