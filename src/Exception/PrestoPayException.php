<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Exception;

class PrestoPayException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $operation = 'config',
        private readonly bool $mayHaveTakenEffect = false,
        private readonly ?string $reconcileBy = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function operation(): string { return $this->operation; }
    public function mayHaveTakenEffect(): bool { return $this->mayHaveTakenEffect; }
    public function reconcileBy(): ?string { return $this->reconcileBy; }
}
