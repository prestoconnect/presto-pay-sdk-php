<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use PrestoUniverse\PrestoPay\Exception\ConfigException;
use PrestoUniverse\PrestoPay\Key\PublicKey;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;

class PrestoPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/prestopay.php', 'prestopay');

        $this->app->singleton(PrestoPay::class, function (): PrestoPay {
            $publicKeyFile = config('prestopay.public_key_file');
            if (!is_string($publicKeyFile) || $publicKeyFile === '') {
                throw new ConfigException('PRESTOPAY_PUBLIC_KEY_FILE is not set: copy .env.example to .env and fill it in');
            }

            return PrestoPay::fromEnv([
                'PRESTOPAY_ENV' => (string) config('prestopay.environment'),
                'PRESTOPAY_MID' => (string) config('prestopay.mid'),
                'PRESTOPAY_PRIVATE_KEY_FILE' => (string) config('prestopay.private_key_file'),
                'PRESTOPAY_PRIVATE_KEY_PASSWORD' => (string) config('prestopay.private_key_password'),
                'PRESTOPAY_PUBLIC_KEY_FILE' => $publicKeyFile,
            ]);
        });

        $this->app->singleton(WebhookVerifier::class, function (): WebhookVerifier {
            $mid = (string) config('prestopay.mid');
            $publicKeyFile = config('prestopay.public_key_file');
            if (!is_string($publicKeyFile) || $publicKeyFile === '') {
                throw new ConfigException('PRESTOPAY_PUBLIC_KEY_FILE is not set: copy .env.example to .env and fill it in');
            }

            return new WebhookVerifier([$mid], [PublicKey::fromFile($publicKeyFile)]);
        });
    }
}
