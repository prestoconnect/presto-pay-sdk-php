<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Tests;

use PHPUnit\Framework\TestCase;
use PrestoUniverse\PrestoPay\Http\CurlTransport;
use PrestoUniverse\PrestoPay\Http\HttpFailure;

final class CurlTransportTest extends TestCase
{
    public function testConnectionRefusalIsKnownNotSent(): void
    {
        try {
            (new CurlTransport())->post('https://127.0.0.1:1/', '{}', 1.0);
            self::fail('Expected local connection refusal');
        } catch (HttpFailure $error) {
            self::assertTrue($error->requestNotSent);
        }
    }
}
