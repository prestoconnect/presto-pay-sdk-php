<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Webhook;

use PrestoUniverse\PrestoPay\Exception\ResponseException;
use PrestoUniverse\PrestoPay\Exception\SignatureException;

enum NotifyAck
{
    case Ok;
    case Resend;

    public function body(): string
    {
        return $this === self::Ok ? '{"resend":false}' : '{"resend":true}';
    }

    public static function forThrowable(\Throwable $error): self
    {
        return $error instanceof SignatureException || $error instanceof ResponseException ? self::Ok : self::Resend;
    }
}
