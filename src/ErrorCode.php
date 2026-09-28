<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay;

final class ErrorCode
{
    public const INVALID_TIMESTAMP = '1004';
    public const EXPIRED_TIMESTAMP = '1005';
    public const INVALID_SIGNATURE = '1006';
    public const SIGNATURE_VERIFICATION_FAILED = '1007';
    public const INVALID_INPUT = '1201';
    public const DUPLICATE_TXN_REF_NUM = '1203';
    public const PAYMENT_NOT_FOUND = '1212';
    public const INVALID_REVERSAL_STATUS = '1219';
    public const INVALID_REFUND_STATUS = '1227';

    private function __construct() {}
}
