<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Result;

final readonly class RefundResult
{
    public string $paymentRefNum;
    public ?string $prestoRefundRefNum;
    public ?int $refundAmount;
    public ?int $amount;
    public ?string $currencyCode;
    public ?string $paymentStatus;
    public ?string $refundedDate;
    /** @var array<array-key, mixed> */
    public array $raw;

    public function __construct(\stdClass $body)
    {
        $this->paymentRefNum = Fields::requiredString($body, 'paymentRefNum');
        $this->prestoRefundRefNum = Fields::optionalString($body, 'prestoRefundRefNum');
        $this->refundAmount = Fields::optionalInt($body, 'refundAmount');
        $this->amount = Fields::optionalInt($body, 'amount');
        $this->currencyCode = Fields::optionalString($body, 'currencyCode');
        $this->paymentStatus = Fields::optionalString($body, 'paymentStatus');
        $this->refundedDate = Fields::optionalString($body, 'refundedDate');
        $this->raw = (array) $body;
    }
}
