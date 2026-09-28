<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Request;

use PrestoUniverse\PrestoPay\Exception\ConfigException;

final readonly class ReverseRequest
{
    public function __construct(public string $prestoMrn, public string $reversalRefNum, public ?string $paymentRefNum = null, public ?string $txnRefNum = null, public ?string $remark = null, public ?string $notifyUrl = null)
    {
        if ($prestoMrn === '' || $reversalRefNum === '' || (($paymentRefNum === null || $paymentRefNum === '') && ($txnRefNum === null || $txnRefNum === ''))) {
            throw new ConfigException('prestoMrn, reversalRefNum and a paymentRefNum or txnRefNum are required');
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
