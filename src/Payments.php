<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay;

use PrestoUniverse\PrestoPay\Exception\ResponseException;
use PrestoUniverse\PrestoPay\Request\InitRequest;
use PrestoUniverse\PrestoPay\Request\QueryRequest;
use PrestoUniverse\PrestoPay\Request\ReverseRequest;
use PrestoUniverse\PrestoPay\Request\RefundRequest;
use PrestoUniverse\PrestoPay\Result\InitResult;
use PrestoUniverse\PrestoPay\Result\QueryResult;
use PrestoUniverse\PrestoPay\Result\ReverseResult;
use PrestoUniverse\PrestoPay\Result\RefundResult;

final readonly class Payments
{
    public function __construct(private PrestoPay $client) {}

    public function init(InitRequest $request): InitResult
    {
        return $this->map('init', $request->toWire(), $request->txnRefNum, static fn (\stdClass $body): InitResult => new InitResult($body));
    }

    public function query(QueryRequest $request): QueryResult
    {
        return $this->map('query', $request->toWire(), null, static fn (\stdClass $body): QueryResult => new QueryResult($body));
    }

    public function reverse(ReverseRequest $request): ReverseResult
    {
        return $this->map('reverse', $request->toWire(), $request->paymentRefNum, static fn (\stdClass $body): ReverseResult => new ReverseResult($body));
    }

    public function refund(RefundRequest $request): RefundResult
    {
        return $this->map('refund', $request->toWire(), $request->paymentRefNum, static fn (\stdClass $body): RefundResult => new RefundResult($body));
    }

    /**
     * @template T of object
     * @param array<string, mixed> $fields
     * @param callable(\stdClass): T $mapper
     * @return T
     */
    private function map(string $operation, array $fields, ?string $reconcileBy, callable $mapper): object
    {
        $body = $this->client->post($operation, '/v1/ext/payment/' . $operation, $fields, $reconcileBy);
        try {
            return $mapper($body);
        } catch (ResponseException $error) {
            throw new ResponseException($error->getMessage(), $operation, $operation !== 'query', $reconcileBy, $error);
        }
    }
}
