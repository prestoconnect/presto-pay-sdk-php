<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Result;

final readonly class InitResult
{
    public string $paymentRefNum;
    public string $paymentStatus;
    public ?string $paymentUrl;
    public ?string $txnRefNum;
    public ?string $userRefNum;
    public ?int $amount;
    public ?string $currencyCode;
    public ?string $paymentRequestDate;
    public ?string $paymentFinalisedDate;
    public ?string $additionalData;
    /** @var array<array-key, mixed> */
    public array $raw;

    public function __construct(\stdClass $body)
    {
        $this->paymentRefNum = Fields::requiredString($body, 'paymentRefNum');
        $this->paymentStatus = Fields::requiredString($body, 'paymentStatus');
        $this->paymentUrl = Fields::optionalString($body, 'paymentUrl');
        $this->txnRefNum = Fields::optionalString($body, 'txnRefNum');
        $this->userRefNum = Fields::optionalString($body, 'userRefNum');
        $this->amount = Fields::optionalInt($body, 'amount');
        $this->currencyCode = Fields::optionalString($body, 'currencyCode');
        $this->paymentRequestDate = Fields::optionalString($body, 'paymentRequestDate');
        $this->paymentFinalisedDate = Fields::optionalString($body, 'paymentFinalisedDate');
        $this->additionalData = Fields::optionalString($body, 'additionalData');
        $this->raw = (array) $body;
    }
}
