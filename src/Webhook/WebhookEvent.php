<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Webhook;

use PrestoUniverse\PrestoPay\Result\PaymentDetail;

final readonly class WebhookEvent
{
    /**
     * @param list<PaymentDetail> $paymentDetails
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $eventCode,
        public string $mid,
        public string $prestoMrn,
        public string $paymentRefNum,
        public string $txnRefNum,
        public string $eventRefNum,
        public string $eventTs,
        public int $amount,
        public string $currencyCode,
        public string $ts,
        public bool $success,
        public ?string $userRefNum,
        public ?string $additionalData,
        public array $paymentDetails,
        public string $paymentStatus,
        public array $raw,
    ) {}
}
