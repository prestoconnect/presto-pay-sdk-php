<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Tests;

use PHPUnit\Framework\TestCase;
use PrestoUniverse\PrestoPay\Canonicalizer;
use PrestoUniverse\PrestoPay\Environment;
use PrestoUniverse\PrestoPay\Exception\ApiException;
use PrestoUniverse\PrestoPay\Exception\SignatureException;
use PrestoUniverse\PrestoPay\Exception\TransportException;
use PrestoUniverse\PrestoPay\Http\HttpFailure;
use PrestoUniverse\PrestoPay\Http\HttpResponse;
use PrestoUniverse\PrestoPay\Http\HttpTransport;
use PrestoUniverse\PrestoPay\Internal\JsonCodec;
use PrestoUniverse\PrestoPay\Key\PrivateKey;
use PrestoUniverse\PrestoPay\Key\PublicKey;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\InitRequest;
use PrestoUniverse\PrestoPay\Request\QueryRequest;
use PrestoUniverse\PrestoPay\Request\RefundRequest;
use PrestoUniverse\PrestoPay\Signer;
use PrestoUniverse\PrestoPay\Timestamp;
use PrestoUniverse\PrestoPay\TxnType;
use PrestoUniverse\PrestoPay\Verifier;

final class PaymentsTest extends TestCase
{
    public function testInitAndRefundRequests(): void
    {
        $key = PrivateKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-key.pem');
        $transport = new class($key) implements HttpTransport {
            public array $requests = [];
            public function __construct(private PrivateKey $key) {}
            public function post(string $url, string $body, float $timeoutSeconds): HttpResponse
            {
                $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $this->requests[] = [$url, $request];
                $response = ['prestoMrn' => $request['prestoMrn'], 'success' => true, 'ts' => Timestamp::now(), 'errorCode' => '', 'errorMessage' => '', 'paymentRefNum' => 'payment-1', 'paymentStatus' => 'PendingAuthorise'];
                if (str_ends_with($url, '/refund')) {
                    $response['prestoRefundRefNum'] = 'refund-1';
                    $response['refundAmount'] = 100;
                } else {
                    $response['paymentUrl'] = 'https://example.test/pay';
                    $response['txnRefNum'] = $request['txnRefNum'];
                }
                $response['signature'] = Signer::sign(Canonicalizer::canonicalize($response), $this->key);
                return new HttpResponse(200, [], json_encode($response, JSON_THROW_ON_ERROR));
            }
        };
        $client = new PrestoPay(Environment::Staging, 'merchant-1', $key, [PublicKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-public.pem')], $transport);
        $init = $client->payments()->init(new InitRequest('mrn-1', TxnType::WebPay, 'txn-1', 'Order 1', amount: 100, currencyCode: 'MYR', redirectUrl: 'https://example.test/return'));
        self::assertSame('payment-1', $init->paymentRefNum);
        self::assertSame('https://example.test/pay', $init->paymentUrl);
        self::assertTrue(Verifier::verifyBody(JsonCodec::decode(json_encode($transport->requests[0][1], JSON_THROW_ON_ERROR)), [PublicKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-public.pem')]));
        $refund = $client->payments()->refund(new RefundRequest('mrn-1', 'payment-1', 'refund-1', 'Customer request', 100));
        self::assertSame(100, $refund->refundAmount);
        self::assertSame('/v1/ext/payment/refund', parse_url($transport->requests[1][0], PHP_URL_PATH));
        self::assertArrayNotHasKey('paymentMethod', $transport->requests[1][1]);
    }

    public function testAmbiguousWriteIsNeverRetried(): void
    {
        $key = PrivateKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-key.pem');
        $transport = new class implements HttpTransport {
            public int $calls = 0;
            public function post(string $url, string $body, float $timeoutSeconds): HttpResponse
            {
                ++$this->calls;
                throw new HttpFailure('connection lost after write', false);
            }
        };
        $client = new PrestoPay(Environment::Staging, 'merchant-1', $key, [PublicKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-public.pem')], $transport);
        try {
            $client->payments()->refund(new RefundRequest('mrn-1', 'payment-1', 'refund-1', 'Customer request'));
            self::fail('Expected an ambiguous transport failure');
        } catch (TransportException $error) {
            self::assertTrue($error->mayHaveTakenEffect());
            self::assertSame('payment-1', $error->reconcileBy());
            self::assertSame(1, $transport->calls);
        }
    }

    public function testProvenUnsentWriteIsRetried(): void
    {
        $key = PrivateKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-key.pem');
        $transport = new class($key) implements HttpTransport {
            public int $calls = 0;
            public function __construct(private PrivateKey $key) {}
            public function post(string $url, string $body, float $timeoutSeconds): HttpResponse
            {
                if (++$this->calls === 1) {
                    throw new HttpFailure('connection refused before any bytes were sent', true);
                }
                $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $response = ['prestoMrn' => $request['prestoMrn'], 'success' => true, 'ts' => Timestamp::now(), 'errorCode' => '', 'errorMessage' => '', 'paymentRefNum' => $request['paymentRefNum'], 'paymentStatus' => 'Authorised', 'refundAmount' => 100, 'refundDetails' => '[]', 'paymentDetails' => '[]'];
                $response['signature'] = Signer::sign(Canonicalizer::canonicalize($response), $this->key);
                return new HttpResponse(200, [], json_encode($response, JSON_THROW_ON_ERROR));
            }
        };
        $client = new PrestoPay(Environment::Staging, 'merchant-1', $key, [PublicKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-public.pem')], $transport);
        $refund = $client->payments()->refund(new RefundRequest('mrn-1', 'payment-1', 'refund-1', 'Customer request'));
        self::assertSame('payment-1', $refund->paymentRefNum);
        self::assertSame(2, $transport->calls);
    }

    public function testQueryRetriesTransportFailure(): void
    {
        $key = PrivateKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-key.pem');
        $transport = new class($key) implements HttpTransport {
            public int $calls = 0;
            public function __construct(private PrivateKey $key) {}
            public function post(string $url, string $body, float $timeoutSeconds): HttpResponse
            {
                if (++$this->calls === 1) {
                    throw new HttpFailure('temporary connection failure', false);
                }
                $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $response = ['prestoMrn' => $request['prestoMrn'], 'success' => true, 'ts' => Timestamp::now(), 'errorCode' => '', 'errorMessage' => '', 'paymentRefNum' => 'payment-1', 'paymentStatus' => 'Authorised', 'txnRefNum' => $request['txnRefNum'], 'refundDetails' => '[]', 'paymentDetails' => '[]'];
                $response['signature'] = Signer::sign(Canonicalizer::canonicalize($response), $this->key);
                return new HttpResponse(200, [], json_encode($response, JSON_THROW_ON_ERROR));
            }
        };
        $client = new PrestoPay(Environment::Staging, 'merchant-1', $key, [PublicKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-public.pem')], $transport);
        $result = $client->payments()->query(new QueryRequest('mrn-1', txnRefNum: 'txn-1'));
        self::assertSame('Authorised', $result->paymentStatus);
        self::assertSame(2, $transport->calls);
    }

    public function testInvalidSignedResponseIsAmbiguousForWrite(): void
    {
        $key = PrivateKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-key.pem');
        $transport = new class implements HttpTransport {
            public function post(string $url, string $body, float $timeoutSeconds): HttpResponse
            {
                return new HttpResponse(200, [], '{"prestoMrn":"mrn-1","success":true,"ts":"20260924133756.056","paymentRefNum":"payment-1","signature":"bad"}');
            }
        };
        $client = new PrestoPay(Environment::Staging, 'merchant-1', $key, [PublicKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-public.pem')], $transport);
        try {
            $client->payments()->init(new InitRequest('mrn-1', TxnType::WebPay, 'txn-1', 'Order 1', redirectUrl: 'https://example.test/return'));
            self::fail('Expected signature rejection');
        } catch (SignatureException $error) {
            self::assertTrue($error->mayHaveTakenEffect());
            self::assertSame('txn-1', $error->reconcileBy());
        }
    }

    public function testSignedBusinessErrorDoesNotClaimRefundSucceeded(): void
    {
        $key = PrivateKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-key.pem');
        $transport = new class($key) implements HttpTransport {
            public function __construct(private PrivateKey $key) {}
            public function post(string $url, string $body, float $timeoutSeconds): HttpResponse
            {
                $response = ['success' => false, 'ts' => Timestamp::now(), 'errorCode' => '1242', 'errorMessage' => 'Manual refund required'];
                $response['signature'] = Signer::sign(Canonicalizer::canonicalize($response), $this->key);
                return new HttpResponse(200, [], json_encode($response, JSON_THROW_ON_ERROR));
            }
        };
        $client = new PrestoPay(Environment::Staging, 'merchant-1', $key, [PublicKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-public.pem')], $transport);
        try {
            $client->payments()->refund(new RefundRequest('mrn-1', 'payment-1', 'refund-1', 'Customer request'));
            self::fail('Expected business error');
        } catch (ApiException $error) {
            self::assertSame('1242', $error->errorCode());
            self::assertFalse($error->mayHaveTakenEffect());
        }
    }
}
