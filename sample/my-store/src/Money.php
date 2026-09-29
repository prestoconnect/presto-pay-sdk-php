<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Sample\MyStore;

final class Money
{
    private function __construct() {}

    /**
     * Converts a ringgit amount typed by a shopper (e.g. "10", "10.5", "10.50") to sen (minor units) for the
     * gateway's `amount` field. Returns null for anything that is not a plain non-negative decimal with at
     * most two fraction digits, so the caller can report a validation error instead of guessing.
     */
    public static function toMinorUnits(string $amountInRinggit): ?int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', trim($amountInRinggit), $matches) !== 1) {
            return null;
        }
        $cents = str_pad($matches[2] ?? '', 2, '0');
        return ((int) $matches[1]) * 100 + (int) $cents;
    }

    public static function formatMinorUnits(int $amountInMinorUnits): string
    {
        return number_format($amountInMinorUnits / 100, 2, '.', ',');
    }

    public static function nextTxnRefNum(): string
    {
        return 'demo-' . bin2hex(random_bytes(8));
    }
}
