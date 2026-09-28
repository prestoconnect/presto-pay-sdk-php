<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Tests;

use PHPUnit\Framework\TestCase;
use PrestoUniverse\PrestoPay\PrestoPay;
use PrestoUniverse\PrestoPay\Request\QueryRequest;

final class StagingSmokeTest extends TestCase
{
    public function testQueryExistingStagingPayment(): void
    {
        if (getenv('PRESTOPAY_STAGING_SMOKE') !== '1') {
            self::markTestSkipped('Set PRESTOPAY_STAGING_SMOKE=1 with staging credentials to run');
        }
        $required = ['PRESTOPAY_MID', 'PRESTOPAY_MRN', 'PRESTOPAY_PRIVATE_KEY_FILE', 'PRESTOPAY_PUBLIC_KEY_FILE', 'PRESTOPAY_STAGING_PAYMENT_REF_NUM'];
        $config = ['PRESTOPAY_ENV' => 'staging'];
        foreach ($required as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                self::fail('Missing staging configuration: ' . $name);
            }
            $config[$name] = $value;
        }
        $result = PrestoPay::fromEnv($config)->payments()->query(new QueryRequest(
            prestoMrn: $config['PRESTOPAY_MRN'],
            paymentRefNum: $config['PRESTOPAY_STAGING_PAYMENT_REF_NUM'],
        ));
        self::assertSame($config['PRESTOPAY_STAGING_PAYMENT_REF_NUM'], $result->paymentRefNum);
    }
}
