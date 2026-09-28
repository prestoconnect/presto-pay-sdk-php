<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Key;

use PrestoUniverse\PrestoPay\Exception\ConfigException;

final class PrivateKey
{
    private function __construct(private readonly \OpenSSLAsymmetricKey $key) {}

    public static function fromPem(string $pem, ?string $password = null): self
    {
        $key = openssl_pkey_get_private($pem, $password ?? '');
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($key === false || $details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new ConfigException('Expected an RSA private key in PEM format');
        }
        return new self($key);
    }

    public static function fromFile(string $path, ?string $password = null): self
    {
        $pem = @file_get_contents($path);
        if ($pem === false) {
            throw new ConfigException('Cannot read private key file');
        }
        return self::fromPem($pem, $password);
    }

    public function native(): \OpenSSLAsymmetricKey { return $this->key; }
    public function __debugInfo(): array { return ['privateKey' => '[redacted]']; }
    public function __serialize(): array { throw new ConfigException('Private keys cannot be serialized'); }
}
