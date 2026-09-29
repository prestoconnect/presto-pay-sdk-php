<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay;

final class PaymentStatus
{
    public const PENDING_AUTHORISE = 'PendingAuthorise';
    public const AUTHORISED = 'Authorised';
    public const FAILED = 'Failed';
    public const CANCELLED = 'Cancelled';
    public const EXPIRED = 'Expired';
    public const PENDING_REVERSE = 'PendingReverse';
    public const REVERSED = 'Reversed';
    public const PENDING_REFUND = 'PendingRefund';
    public const REFUNDED = 'Refunded';
    public const PARTIAL_REFUNDED = 'PartialRefunded';

    private function __construct() {}
}
