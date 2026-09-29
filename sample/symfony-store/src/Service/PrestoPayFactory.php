<?php

declare(strict_types=1);

namespace App\Service;

use PrestoUniverse\PrestoPay\Key\PublicKey;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;

/**
 * Builds the SDK's own client and verifier from config/services.yaml-bound env vars. PrestoPay::fromEnv()
 * takes an array rather than reading the process environment itself, which suits Symfony's config system
 * well: the %env(...)% bindings below are this factory's constructor arguments, not calls to getenv().
 */
final readonly class PrestoPayFactory
{
    public function __construct(
        private string $environment,
        private string $mid,
        private string $mrn,
        private string $privateKeyFile,
        private string $privateKeyPassword,
        private string $publicKeyFile,
        private string $publicBaseUrl,
    ) {}

    public function createClient(): PrestoPay
    {
        return PrestoPay::fromEnv([
            'PRESTOPAY_ENV' => $this->environment,
            'PRESTOPAY_MID' => $this->mid,
            'PRESTOPAY_PRIVATE_KEY_FILE' => $this->privateKeyFile,
            'PRESTOPAY_PRIVATE_KEY_PASSWORD' => $this->privateKeyPassword,
            'PRESTOPAY_PUBLIC_KEY_FILE' => $this->publicKeyFile,
        ]);
    }

    public function createWebhookVerifier(): WebhookVerifier
    {
        return new WebhookVerifier([$this->mid], [PublicKey::fromFile($this->publicKeyFile)]);
    }

    public function merchantRefNum(): string
    {
        return $this->mrn;
    }

    public function notifyUrl(): string
    {
        return rtrim($this->publicBaseUrl, '/') . '/presto/notify';
    }

    public function returnUrl(string $txnRefNum): string
    {
        return rtrim($this->publicBaseUrl, '/') . '/return/' . $txnRefNum;
    }
}
