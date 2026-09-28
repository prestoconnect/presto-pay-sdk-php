<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Http;

final class HttpFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $requestNotSent, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
