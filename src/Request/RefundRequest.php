<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Request;

use PrestoUniverse\PrestoPay\Exception\ConfigException;

final readonly class RefundRequest
{
    public function __construct(public string $prestoMrn, public string $paymentRefNum, public string $refundRefNum, public string $remark, public ?int $amount = null, public ?string $notifyUrl = null)
    {
        if ($prestoMrn === '' || $paymentRefNum === '' || $refundRefNum === '' || $remark === '') {
            throw new ConfigException('prestoMrn, paymentRefNum, refundRefNum and remark are required');
        }
        if ($amount !== null && ($amount <= 0 || $amount > 9007199254740991)) {
            throw new ConfigException('refund amount must be a positive safe integer');
        }
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        /** @var array<string, mixed> $fields */
        $fields = get_object_vars($this);
        return array_filter($fields, static fn (mixed $value): bool => $value !== null);
    }
}
