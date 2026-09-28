<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Tests;

use PHPUnit\Framework\TestCase;
use PrestoUniverse\PrestoPay\Canonicalizer;
use PrestoUniverse\PrestoPay\Key\PrivateKey;
use PrestoUniverse\PrestoPay\Key\PublicKey;
use PrestoUniverse\PrestoPay\Signer;
use PrestoUniverse\PrestoPay\Timestamp;
use PrestoUniverse\PrestoPay\Verifier;
use PrestoUniverse\PrestoPay\Internal\JsonCodec;

final class VectorTest extends TestCase
{
    public function testCanonicalVectors(): void
    {
        $vectors = json_decode((string) file_get_contents(__DIR__ . '/../spec/vectors/canonical.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($vectors['cases'] as $case) {
            $json = $case['bodyRaw'] ?? json_encode($case['body'], JSON_THROW_ON_ERROR);
            if (isset($case['reject'])) {
                try {
                    Canonicalizer::fromJson($json);
                    self::fail($case['name'] . ' should be rejected');
                } catch (\PrestoUniverse\PrestoPay\Exception\ResponseException) {
                    continue;
                }
            }
            self::assertSame($case['canonical'], Canonicalizer::fromJson($json), $case['name']);
            if (isset($case['verifyWith'])) {
                $key = PublicKey::fromFile(__DIR__ . '/../spec/' . $case['verifyWith']);
                self::assertTrue(Verifier::verifyBody(JsonCodec::decode($json), [$key]), $case['name']);
            }
        }
    }

    public function testSignatureVectors(): void
    {
        $vectors = json_decode((string) file_get_contents(__DIR__ . '/../spec/vectors/signatures.json'), true, 512, JSON_THROW_ON_ERROR);
        $key = PrivateKey::fromFile(__DIR__ . '/../spec/keys/test-merchant-key.pem');
        foreach ($vectors['cases'] as $case) {
            self::assertSame($case['signature'], Signer::sign($case['canonical'], $key), $case['name']);
        }
    }

    public function testTimestampVectors(): void
    {
        $vectors = json_decode((string) file_get_contents(__DIR__ . '/../spec/vectors/timestamps.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($vectors['format'] as $case) {
            self::assertSame($case['ts'], Timestamp::format($case['epochMillis']), $case['name']);
            self::assertSame($case['epochMillis'], Timestamp::parse($case['ts']), $case['name']);
        }
        foreach ($vectors['parse'] as $case) {
            $this->expectTimestampReject($case['ts']);
        }
    }

    private function expectTimestampReject(string $timestamp): void
    {
        try {
            Timestamp::parse($timestamp);
            self::fail('Invalid timestamp accepted: ' . $timestamp);
        } catch (\PrestoUniverse\PrestoPay\Exception\ResponseException) {
            self::assertTrue(true);
        }
    }
}
