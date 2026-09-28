<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay;

use PrestoUniverse\PrestoPay\Exception\ResponseException;
use PrestoUniverse\PrestoPay\Internal\JsonCodec;

final class Canonicalizer
{
    public static function fromJson(string $json): string
    {
        return self::canonicalize(JsonCodec::decode($json));
    }

    /** @param array<string, mixed>|\stdClass $body */
    public static function canonicalize(\stdClass|array $body): string
    {
        $values = (array) $body;
        unset($values['signature']);
        uksort($values, static fn (string|int $left, string|int $right): int => strcmp((string) $left, (string) $right));
        $parts = [];
        foreach ($values as $value) {
            $parts[] = match (true) {
                $value === null => '',
                is_string($value) => $value,
                is_int($value) => self::safeInteger($value),
                is_bool($value) => $value ? 'true' : 'false',
                default => throw new ResponseException('Canonical body must be flat and contain no floating-point values'),
            };
        }
        return implode(':', $parts);
    }

    private static function safeInteger(int $value): string
    {
        if ($value > 9007199254740991 || $value < -9007199254740991) {
            throw new ResponseException('Integer exceeds the safe range');
        }
        return (string) $value;
    }
}
