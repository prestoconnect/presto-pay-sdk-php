<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Exception;

final class ApiException extends PrestoPayException
{
    public function __construct(string $message, string $operation, bool $mayHaveTakenEffect, ?string $reconcileBy, private readonly ?int $httpStatus = null, private readonly ?string $errorCode = null, private readonly ?string $errorMessage = null)
    {
        parent::__construct($message, $operation, $mayHaveTakenEffect, $reconcileBy);
    }

    public function httpStatus(): ?int { return $this->httpStatus; }
    public function errorCode(): ?string { return $this->errorCode; }
    public function errorMessage(): ?string { return $this->errorMessage; }
}
