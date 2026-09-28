<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay;

use PrestoUniverse\PrestoPay\Exception\ConfigException;
use PrestoUniverse\PrestoPay\Key\PrivateKey;

final class Signer
{
    public static function sign(string $canonical, PrivateKey $key): string
    {
        $signature = '';
        if (!openssl_sign($canonical, $signature, $key->native(), OPENSSL_ALGO_SHA256)) {
            throw new ConfigException('Could not sign request');
        }
        if (!is_string($signature)) {
            throw new ConfigException('Could not sign request');
        }
        return base64_encode($signature);
    }
}
