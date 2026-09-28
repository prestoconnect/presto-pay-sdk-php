<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Tests;

use PHPUnit\Framework\TestCase;
use PrestoUniverse\PrestoPay\Http\Psr18Transport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class Psr18TransportTest extends TestCase
{
    public function testAdapterSendsSignedBodyAndMapsResponse(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())->method('createRequest')->with('POST', 'https://example.test/pay')->willReturn($request);
        $stream = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->expects(self::once())->method('createStream')->with('{"hello":"world"}')->willReturn($stream);
        $responseBody = $this->createMock(StreamInterface::class);
        $responseBody->method('__toString')->willReturn('{"success":true}');
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->willReturn(['X-Test' => ['value']]);
        $response->method('getBody')->willReturn($responseBody);
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('sendRequest')->with($request)->willReturn($response);
        $mapped = (new Psr18Transport($client, $requestFactory, $streamFactory))->post('https://example.test/pay', '{"hello":"world"}', 5.0);
        self::assertSame(200, $mapped->status);
        self::assertSame('{"success":true}', $mapped->body);
        self::assertSame('value', $mapped->header('x-test'));
    }
}
