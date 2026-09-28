<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Exception;

final class TransportException extends PrestoPayException
{
    public function __construct(string $message, string $operation, bool $mayHaveTakenEffect, ?string $reconcileBy, private readonly bool $requestNotSent, ?\Throwable $previous = null)
    {
        parent::__construct($message, $operation, $mayHaveTakenEffect, $reconcileBy, $previous);
    }

    public function requestNotSent(): bool { return $this->requestNotSent; }
}
