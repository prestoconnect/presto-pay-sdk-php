<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Result;

final readonly class QueryResult
{
    public string $paymentRefNum;
    public ?string $paymentStatus;
    public ?string $txnRefNum;
    public ?string $userRefNum;
    public ?int $amount;
    public ?string $currencyCode;
    public ?string $paymentRequestDate;
    public ?string $paymentFinalisedDate;
    public ?string $reversalRefNum;
    public ?string $prestoReversalRefNum;
    public ?string $reversalStatus;
    public ?string $reversalDate;
    public ?string $refundRefNum;
    public ?string $prestoRefundRefNum;
    public ?string $refundStatus;
    public ?string $refundRequestDate;
    public ?string $refundFinalisedDate;
    public ?string $additionalData;
    /** @var list<RefundDetail> */
    public array $refundDetails;
    /** @var list<PaymentDetail> */
    public array $paymentDetails;
    /** @var array<array-key, mixed> */
    public array $raw;

    public function __construct(\stdClass $body)
    {
        $this->paymentRefNum = Fields::requiredString($body, 'paymentRefNum');
        $this->paymentStatus = Fields::optionalString($body, 'paymentStatus');
        $this->txnRefNum = Fields::optionalString($body, 'txnRefNum');
        $this->userRefNum = Fields::optionalString($body, 'userRefNum');
        $this->amount = Fields::optionalInt($body, 'amount');
        $this->currencyCode = Fields::optionalString($body, 'currencyCode');
        $this->paymentRequestDate = Fields::optionalString($body, 'paymentRequestDate');
        $this->paymentFinalisedDate = Fields::optionalString($body, 'paymentFinalisedDate');
        $this->reversalRefNum = Fields::optionalString($body, 'reversalRefNum');
        $this->prestoReversalRefNum = Fields::optionalString($body, 'prestoReversalRefNum');
        $this->reversalStatus = Fields::optionalString($body, 'reversalStatus');
        $this->reversalDate = Fields::optionalString($body, 'reversalDate');
        $this->refundRefNum = Fields::optionalString($body, 'refundRefNum');
        $this->prestoRefundRefNum = Fields::optionalString($body, 'prestoRefundRefNum');
        $this->refundStatus = Fields::optionalString($body, 'refundStatus');
        $this->refundRequestDate = Fields::optionalString($body, 'refundRequestDate');
        $this->refundFinalisedDate = Fields::optionalString($body, 'refundFinalisedDate');
        $this->additionalData = Fields::optionalString($body, 'additionalData');
        $this->refundDetails = array_map(static fn (\stdClass $item): RefundDetail => new RefundDetail($item), Fields::list($body, 'refundDetails'));
        $this->paymentDetails = array_map(static fn (\stdClass $item): PaymentDetail => new PaymentDetail($item), Fields::list($body, 'paymentDetails'));
        $this->raw = (array) $body;
    }
}
