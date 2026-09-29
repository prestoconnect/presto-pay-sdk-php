<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Sample\MyStore;

use PrestoUniverse\PrestoPay\Exception\ConfigException;
use PrestoUniverse\PrestoPay\Key\PublicKey;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;

final readonly class AppConfig
{
    private function __construct(
        public PrestoPay $client,
        public WebhookVerifier $webhookVerifier,
        public string $prestoMrn,
        public string $publicBaseUrl,
        public ActivityStore $activityStore,
    ) {}

    public static function fromEnvironment(): self
    {
        self::loadDotEnv(dirname(__DIR__) . '/.env');

        $mid = self::requireEnv('PRESTOPAY_MID');
        // PRESTOPAY_MRN matches this SDK's own README; PRESTO_MRN is also accepted so the same .env works
        // against the Go SDK's sample, which uses that name.
        $prestoMrn = getenv('PRESTOPAY_MRN') ?: getenv('PRESTO_MRN');
        if ($prestoMrn === false || $prestoMrn === '') {
            throw new ConfigException('PRESTOPAY_MRN is not set: copy .env.example to .env and fill it in');
        }
        $privateKeyFile = self::requireEnv('PRESTOPAY_PRIVATE_KEY_FILE');
        $publicKeyFile = self::requireEnv('PRESTOPAY_PUBLIC_KEY_FILE');
        $privateKeyPassword = getenv('PRESTOPAY_PRIVATE_KEY_PASSWORD');

        $client = PrestoPay::fromEnv([
            'PRESTOPAY_ENV' => getenv('PRESTOPAY_ENV') ?: 'staging',
            'PRESTOPAY_MID' => $mid,
            'PRESTOPAY_PRIVATE_KEY_FILE' => $privateKeyFile,
            'PRESTOPAY_PRIVATE_KEY_PASSWORD' => $privateKeyPassword !== false ? $privateKeyPassword : '',
            'PRESTOPAY_PUBLIC_KEY_FILE' => $publicKeyFile,
        ]);

        $webhookVerifier = new WebhookVerifier([$mid], [PublicKey::fromFile($publicKeyFile)]);

        $port = getenv('PORT') ?: '8080';
        $publicBaseUrl = getenv('PUBLIC_URL');
        if ($publicBaseUrl === false || $publicBaseUrl === '') {
            $publicBaseUrl = 'http://localhost:' . $port;
        }

        $activityStore = new ActivityStore(dirname(__DIR__) . '/var/activity.json');

        return new self($client, $webhookVerifier, $prestoMrn, rtrim($publicBaseUrl, '/'), $activityStore);
    }

    public function notifyUrl(): string
    {
        return $this->publicBaseUrl . '/presto/notify';
    }

    public function returnUrl(string $txnRefNum): string
    {
        return $this->publicBaseUrl . '/return/' . rawurlencode($txnRefNum);
    }

    private static function requireEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new ConfigException($name . ' is not set: copy .env.example to .env and fill it in');
        }
        return $value;
    }

    /**
     * Sets variables from a .env file without overriding anything already present in the real environment.
     * A few lines rather than a dependency, matching the Go sample's own loadDotEnv.
     */
    private static function loadDotEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
            }
        }
    }
}
