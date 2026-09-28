<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Result;

final readonly class ReverseResult
{
    public string $paymentRefNum;
    public ?string $prestoReversalRefNum;
    public ?string $paymentStatus;
    public ?int $amount;
    public ?string $currencyCode;
    /** @var array<array-key, mixed> */
    public array $raw;

    public function __construct(\stdClass $body)
    {
        $this->paymentRefNum = Fields::requiredString($body, 'paymentRefNum');
        $this->prestoReversalRefNum = Fields::optionalString($body, 'prestoReversalRefNum');
        $this->paymentStatus = Fields::optionalString($body, 'paymentStatus');
        $this->amount = Fields::optionalInt($body, 'amount');
        $this->currencyCode = Fields::optionalString($body, 'currencyCode');
        $this->raw = (array) $body;
    }
}
