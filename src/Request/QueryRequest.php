<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Request;

use PrestoUniverse\PrestoPay\Exception\ConfigException;

final readonly class QueryRequest
{
    public function __construct(public string $prestoMrn, public ?string $paymentRefNum = null, public ?string $txnRefNum = null)
    {
        if ($prestoMrn === '' || (($paymentRefNum === null || $paymentRefNum === '') && ($txnRefNum === null || $txnRefNum === ''))) {
            throw new ConfigException('prestoMrn and a paymentRefNum or txnRefNum are required');
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
