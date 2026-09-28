<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Result;

final readonly class RefundDetail
{
    public string $refundRefNum;
    public string $prestoRefundRefNum;
    public string $refundStatus;
    public string $refundRequestDate;
    public ?string $refundFinalisedDate;

    public function __construct(\stdClass $body)
    {
        $this->refundRefNum = Fields::requiredString($body, 'refundRefNum');
        $this->prestoRefundRefNum = Fields::requiredString($body, 'prestoRefundRefNum');
        $this->refundStatus = Fields::requiredString($body, 'refundStatus');
        $this->refundRequestDate = Fields::requiredString($body, 'refundRequestDate');
        $this->refundFinalisedDate = Fields::optionalString($body, 'refundFinalisedDate');
    }
}
