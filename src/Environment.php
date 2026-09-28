<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay;

enum Environment: string
{
    case Staging = 'https://presto-stg-ext.enovax.com';
    case Production = 'https://pay-ext.prestouniverse.com';
}
