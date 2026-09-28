<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay;

enum TxnType: string
{
    case QrPay = 'QrPay';
    case WebPay = 'WebPay';
    case MiniAppPay = 'MiniAppPay';
}
