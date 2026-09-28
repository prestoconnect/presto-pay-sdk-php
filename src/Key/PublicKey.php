<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Key;

use PrestoUniverse\PrestoPay\Exception\ConfigException;

final class PublicKey
{
    private function __construct(private readonly \OpenSSLAsymmetricKey $key) {}

    public static function fromPem(string $pem): self
    {
        $key = openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($key === false || $details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new ConfigException('Expected an RSA public key or certificate in PEM format');
        }
        return new self($key);
    }

    public static function fromDer(string $der): self
    {
        return self::fromPem("-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n");
    }

    public static function fromFile(string $path): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new ConfigException('Cannot read public key file');
        }
        return str_contains($bytes, '-----BEGIN ') ? self::fromPem($bytes) : self::fromDer($bytes);
    }

    public function native(): \OpenSSLAsymmetricKey { return $this->key; }
}
