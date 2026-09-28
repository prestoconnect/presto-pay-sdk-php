<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay;

use PrestoUniverse\PrestoPay\Key\PublicKey;

final class Verifier
{
    /** @param list<PublicKey> $keys */
    public static function verifyBody(\stdClass $body, array $keys): bool
    {
        if (!isset($body->signature) || !is_string($body->signature)) {
            return false;
        }
        $signature = base64_decode($body->signature, true);
        if ($signature === false) {
            return false;
        }
        $canonical = Canonicalizer::canonicalize($body);
        foreach ($keys as $key) {
            if (openssl_verify($canonical, $signature, $key->native(), OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }
        return false;
    }
}
