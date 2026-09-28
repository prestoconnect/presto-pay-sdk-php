<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Tests;

use PHPUnit\Framework\TestCase;
use PrestoUniverse\PrestoPay\Canonicalizer;
use PrestoUniverse\PrestoPay\Exception\SignatureException;
use PrestoUniverse\PrestoPay\Key\PrivateKey;
use PrestoUniverse\PrestoPay\Key\PublicKey;
use PrestoUniverse\PrestoPay\Signer;
use PrestoUniverse\PrestoPay\Timestamp;
use PrestoUniverse\PrestoPay\Webhook\NotifyAck;
use PrestoUniverse\PrestoPay\Webhook\WebhookVerifier;

final class WebhookTest extends TestCase
{
    public function testSignedWebhookAndFreshRedelivery(): void
    {
        $key = PrivateKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-key.pem');
        $verifier = new WebhookVerifier(['merchant-1'], [PublicKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-public.pem')]);
        $now = 1790228276056;
        $body = ['eventCode' => 'Authorised', 'mid' => 'merchant-1', 'prestoMrn' => 'mrn-1', 'paymentRefNum' => 'payment-1', 'txnRefNum' => 'txn-1', 'eventRefNum' => 'event-1', 'eventTs' => Timestamp::format($now), 'amount' => 100, 'currencyCode' => 'MYR', 'ts' => Timestamp::format($now), 'success' => true];
        $body['signature'] = Signer::sign(Canonicalizer::canonicalize($body), $key);
        $event = $verifier->verify(json_encode($body, JSON_THROW_ON_ERROR), $now);
        self::assertSame('event-1', $event->eventRefNum);
        self::assertSame('Authorised', $event->paymentStatus);
        $body['ts'] = Timestamp::format($now + 60_000);
        $body['signature'] = Signer::sign(Canonicalizer::canonicalize($body), $key);
        self::assertSame('event-1', $verifier->verify(json_encode($body, JSON_THROW_ON_ERROR), $now + 60_000)->eventRefNum);
        self::assertSame('{"resend":false}', NotifyAck::Ok->body());
    }

    public function testForeignMerchantIsPermanentFailure(): void
    {
        $key = PrivateKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-key.pem');
        $verifier = new WebhookVerifier(['merchant-1'], [PublicKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-public.pem')]);
        $now = 1790228276056;
        $body = ['eventCode' => 'Authorised', 'mid' => 'another-merchant', 'prestoMrn' => 'mrn-1', 'paymentRefNum' => 'payment-1', 'txnRefNum' => 'txn-1', 'eventRefNum' => 'event-1', 'eventTs' => Timestamp::format($now), 'amount' => 100, 'currencyCode' => 'MYR', 'ts' => Timestamp::format($now), 'success' => true];
        $body['signature'] = Signer::sign(Canonicalizer::canonicalize($body), $key);
        try {
            $verifier->verify(json_encode($body, JSON_THROW_ON_ERROR), $now);
            self::fail('Expected foreign merchant rejection');
        } catch (SignatureException $error) {
            self::assertSame(NotifyAck::Ok, NotifyAck::forThrowable($error));
        }
    }
}
