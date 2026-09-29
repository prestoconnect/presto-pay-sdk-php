<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay;

use PrestoUniverse\PrestoPay\Exception\ApiException;
use PrestoUniverse\PrestoPay\Exception\ConfigException;
use PrestoUniverse\PrestoPay\Exception\ResponseException;
use PrestoUniverse\PrestoPay\Exception\SignatureException;
use PrestoUniverse\PrestoPay\Exception\TransportException;
use PrestoUniverse\PrestoPay\Http\CurlTransport;
use PrestoUniverse\PrestoPay\Http\HttpFailure;
use PrestoUniverse\PrestoPay\Http\HttpTransport;
use PrestoUniverse\PrestoPay\Internal\JsonCodec;
use PrestoUniverse\PrestoPay\Key\PrivateKey;
use PrestoUniverse\PrestoPay\Key\PublicKey;

final class PrestoPay
{
    public const VERSION = '0.1.0-dev';

    private readonly string $baseUrl;
    private readonly HttpTransport $transport;

    /** @param list<PublicKey> $prestoPublicKeys */
    public function __construct(
        Environment|string $environment,
        private readonly string $merchantId,
        private readonly PrivateKey $privateKey,
        private readonly array $prestoPublicKeys,
        ?HttpTransport $transport = null,
        private readonly float $deadline = 30.0,
        private readonly int $retryReads = 2,
        private readonly float $initialBackoff = 0.2,
        private readonly float $maxBackoff = 2.0,
    ) {
        if (PHP_INT_SIZE < 8 || $merchantId === '' || $prestoPublicKeys === [] || $deadline <= 0 || $retryReads < 0
            || $initialBackoff < 0 || $maxBackoff < $initialBackoff) {
            throw new ConfigException('Invalid merchant ID, public keys, deadline, retry or PHP integer width');
        }
        $base = $environment instanceof Environment ? $environment->value : $environment;
        if (parse_url($base, PHP_URL_SCHEME) !== 'https' || parse_url($base, PHP_URL_HOST) === null) {
            throw new ConfigException('Gateway base URL must be HTTPS');
        }
        $this->baseUrl = rtrim($base, '/');
        $this->transport = $transport ?? new CurlTransport();
    }

    public function payments(): Payments { return new Payments($this); }
    public function raw(): Raw { return new Raw($this); }

    /** @param array<string, string> $config */
    public static function fromEnv(array $config, ?HttpTransport $transport = null): self
    {
        $environment = $config['PRESTOPAY_BASE_URL'] ?? match ($config['PRESTOPAY_ENV'] ?? 'staging') {
            'staging' => Environment::Staging->value,
            'production' => Environment::Production->value,
            default => throw new ConfigException('PRESTOPAY_ENV must be staging or production'),
        };
        $private = isset($config['PRESTOPAY_PRIVATE_KEY_FILE'])
            ? PrivateKey::fromFile($config['PRESTOPAY_PRIVATE_KEY_FILE'], $config['PRESTOPAY_PRIVATE_KEY_PASSWORD'] ?? null)
            : PrivateKey::fromPem($config['PRESTOPAY_PRIVATE_KEY'] ?? '', $config['PRESTOPAY_PRIVATE_KEY_PASSWORD'] ?? null);
        $public = isset($config['PRESTOPAY_PUBLIC_KEY_FILE'])
            ? PublicKey::fromFile($config['PRESTOPAY_PUBLIC_KEY_FILE'])
            : PublicKey::fromPem($config['PRESTOPAY_PUBLIC_KEY'] ?? '');
        return new self($environment, $config['PRESTOPAY_MID'] ?? '', $private, [$public], $transport);
    }

    /** @param array<string, mixed> $fields */
    public function post(string $operation, string $path, array $fields, ?string $reconcileBy = null): \stdClass
    {
        if (preg_match('#^/v1/ext/[A-Za-z0-9/_-]+$#D', $path) !== 1) {
            throw new ConfigException('Invalid gateway path');
        }
        $write = $operation !== 'query';
        $started = microtime(true);
        for ($attempt = 0; ; ++$attempt) {
            $remaining = $this->deadline - (microtime(true) - $started);
            if ($remaining <= 0) {
                throw new TransportException('Gateway deadline exceeded', $operation, $write, $reconcileBy, false);
            }
            $body = $fields;
            $body['mid'] = $this->merchantId;
            $body['ts'] = Timestamp::now();
            $body['signature'] = Signer::sign(Canonicalizer::canonicalize($body), $this->privateKey);
            try {
                $response = $this->transport->post($this->baseUrl . $path, JsonCodec::encode($body), $remaining);
            } catch (HttpFailure $error) {
                // A write is retried only when requestNotSent proves nothing reached the gateway; an
                // ambiguous (sent) write failure must never be resent automatically.
                if ($attempt < $this->retryReads && (!$write || $error->requestNotSent)) {
                    $this->backoff($attempt, $started, null);
                    continue;
                }
                throw new TransportException('Gateway transport failed', $operation, $write && !$error->requestNotSent, $reconcileBy, $error->requestNotSent, $error);
            }
            if (!$write && $response->status >= 500 && $attempt < $this->retryReads) {
                $this->backoff($attempt, $started, $response->header('retry-after'));
                continue;
            }
            if ($response->status !== 200) {
                throw new ApiException('Gateway HTTP error', $operation, $write && $response->status >= 500, $reconcileBy, $response->status, $response->header('x-http-error-code'), $response->header('x-http-error'));
            }
            try {
                $decoded = JsonCodec::decode($response->body);
                if (!Verifier::verifyBody($decoded, $this->prestoPublicKeys)) {
                    throw new SignatureException('Gateway response signature is invalid');
                }
                if (!isset($decoded->ts) || !is_string($decoded->ts)) {
                    throw new ResponseException('Gateway response is missing ts');
                }
                Timestamp::parse($decoded->ts);
                if (!isset($decoded->success) || !is_bool($decoded->success)) {
                    throw new ResponseException('Gateway response has no boolean success field');
                }
                if ($decoded->success === false) {
                    $code = isset($decoded->errorCode) && is_string($decoded->errorCode) ? $decoded->errorCode : null;
                    $message = isset($decoded->errorMessage) && is_string($decoded->errorMessage) ? $decoded->errorMessage : null;
                    throw new ApiException('Gateway rejected ' . $operation . ($code !== null ? ' (' . $code . ')' : ''), $operation, $code === '1203' && $operation === 'init', $reconcileBy, 200, $code, $message);
                }
                if (!isset($decoded->prestoMrn) || $decoded->prestoMrn !== ($fields['prestoMrn'] ?? null)) {
                    throw new ResponseException('Gateway merchant reference mismatch');
                }
                if (isset($decoded->txnRefNum) && $decoded->txnRefNum !== '' && isset($fields['txnRefNum']) && $decoded->txnRefNum !== $fields['txnRefNum']) {
                    throw new ResponseException('Gateway transaction reference mismatch');
                }
                return $decoded;
            } catch (ApiException $error) {
                throw $error;
            } catch (SignatureException $error) {
                throw new SignatureException($error->getMessage(), $operation, $write, $reconcileBy, $error);
            } catch (ResponseException $error) {
                throw new ResponseException($error->getMessage(), $operation, $write, $reconcileBy, $error);
            }
        }
    }

    private function backoff(int $attempt, float $started, ?string $retryAfter): void
    {
        $remaining = $this->deadline - (microtime(true) - $started);
        if ($remaining <= 0) {
            return;
        }
        $afterSeconds = self::parseRetryAfter($retryAfter);
        if ($afterSeconds !== null) {
            $seconds = min($afterSeconds, $remaining);
        } elseif ($this->initialBackoff <= 0) {
            $seconds = 0.0;
        } else {
            $cap = min($this->initialBackoff * (2 ** min($attempt, 20)), $this->maxBackoff);
            $seconds = min(random_int(0, (int) ($cap * 1000)) / 1000, $remaining);
        }
        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    private static function parseRetryAfter(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^\d+$/D', $value) === 1) {
            return (float) $value;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        $delta = $timestamp - time();
        return $delta > 0 ? (float) $delta : null;
    }

    public function __debugInfo(): array { return ['merchantId' => $this->merchantId, 'baseUrl' => $this->baseUrl, 'privateKey' => '[redacted]']; }
}
