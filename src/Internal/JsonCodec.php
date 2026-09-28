<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Internal;

use PrestoUniverse\PrestoPay\Exception\ResponseException;

final class JsonCodec
{
    private const MAX_SAFE_INTEGER = '9007199254740991';

    public static function decode(string $json): \stdClass
    {
        self::validateNumbers($json);
        try {
            $body = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ResponseException('Invalid JSON body', previous: $error);
        }
        if (!$body instanceof \stdClass) {
            throw new ResponseException('JSON body must be an object');
        }
        return $body;
    }

    /** @return list<\stdClass> */
    public static function decodeList(string $json): array
    {
        self::validateNumbers($json);
        try {
            $items = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ResponseException('Invalid JSON list', previous: $error);
        }
        if (!is_array($items)) {
            throw new ResponseException('JSON value must be a list');
        }
        foreach ($items as $item) {
            if (!$item instanceof \stdClass) {
                throw new ResponseException('JSON list must contain objects');
            }
        }
        return array_values($items);
    }

    private static function validateNumbers(string $json): void
    {
        // Inspect numeric tokens before json_decode can turn an oversized integer into a float.
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $json, $matches);
        foreach ($matches[0] as $token) {
            if ($token[0] === '"') {
                continue;
            }
            if (strpbrk($token, '.eE') !== false) {
                throw new ResponseException('JSON must contain integer numbers only');
            }
            $digits = ltrim(ltrim($token, '-'), '0');
            if (strlen($digits) > 16 || (strlen($digits) === 16 && strcmp($digits, self::MAX_SAFE_INTEGER) > 0)) {
                throw new ResponseException('JSON integer exceeds the safe range');
            }
        }
    }

    /** @param array<array-key, mixed> $body */
    public static function encode(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $error) {
            throw new \PrestoUniverse\PrestoPay\Exception\ConfigException('Invalid request JSON', previous: $error);
        }
    }
}
