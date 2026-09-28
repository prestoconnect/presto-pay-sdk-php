<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Result;

use PrestoUniverse\PrestoPay\Exception\ResponseException;

final readonly class PaymentDetail
{
    public int $amount;
    public ?string $method;
    public ?string $cardBin;
    public ?string $cardSummary;
    public ?string $cardType;
    public ?string $refNum;

    public function __construct(\stdClass $body)
    {
        if (!isset($body->amount) || !is_int($body->amount)) {
            throw new ResponseException('Payment detail is missing amount');
        }
        $this->amount = $body->amount;
        $this->method = Fields::optionalString($body, 'method');
        $this->cardBin = Fields::optionalString($body, 'cardBin');
        $this->cardSummary = Fields::optionalString($body, 'cardSummary');
        $this->cardType = Fields::optionalString($body, 'cardType');
        $this->refNum = Fields::optionalString($body, 'refNum');
    }
}
