<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Http;

use PrestoUniverse\PrestoPay\Internal\SdkVersion;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class Psr18Transport implements HttpTransport
{
    public function __construct(private ClientInterface $client, private RequestFactoryInterface $requests, private StreamFactoryInterface $streams) {}

    public function post(string $url, string $body, float $timeoutSeconds): HttpResponse
    {
        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('User-Agent', 'presto-pay-sdk-php/' . SdkVersion::CURRENT)
            ->withBody($this->streams->createStream($body));
        try {
            $response = $this->client->sendRequest($request);
        } catch (\Throwable $error) {
            // PSR-18 cannot prove whether request bytes left the process.
            throw new HttpFailure('HTTP client failed', false, $error);
        }
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }
        return new HttpResponse($response->getStatusCode(), $headers, (string) $response->getBody());
    }
}
