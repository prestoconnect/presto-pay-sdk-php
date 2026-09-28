<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Result;

use PrestoUniverse\PrestoPay\Exception\ResponseException;
use PrestoUniverse\PrestoPay\Internal\JsonCodec;

final class Fields
{
    public static function requiredString(\stdClass $body, string $name): string
    {
        $value = $body->$name ?? null;
        if (!is_string($value) || $value === '') {
            throw new ResponseException('Gateway response is missing ' . $name);
        }
        return $value;
    }

    public static function optionalString(\stdClass $body, string $name): ?string
    {
        $value = $body->$name ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new ResponseException('Gateway response has invalid ' . $name);
        }
        return $value;
    }

    public static function optionalInt(\stdClass $body, string $name): ?int
    {
        $value = $body->$name ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value)) {
            throw new ResponseException('Gateway response has invalid ' . $name);
        }
        return $value;
    }

    /** @return list<\stdClass> */
    public static function list(\stdClass $body, string $name): array
    {
        $value = $body->$name ?? null;
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value)) {
            throw new ResponseException('Gateway response has invalid ' . $name);
        }
        try {
            return JsonCodec::decodeList($value);
        } catch (ResponseException $error) {
            throw new ResponseException('Gateway response has invalid ' . $name, previous: $error);
        }
    }
}
