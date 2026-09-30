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
        // Only a webhook that failed verification fails the same way on every redelivery. The same exception types
        // from an outbound call inside the handler (a query for the payment's status, say) are the merchant's own
        // failure, and the event must be delivered again.
        $verificationFailed = $error instanceof SignatureException || $error instanceof ResponseException;
        return $verificationFailed && $error->operation() === 'webhook' ? self::Ok : self::Resend;
    }
}
