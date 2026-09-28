<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay;

use PrestoUniverse\PrestoPay\Exception\ResponseException;

final class Timestamp
{
    public static function now(): string
    {
        return self::format((int) floor(microtime(true) * 1000));
    }

    public static function format(int $epochMillis): string
    {
        $seconds = intdiv($epochMillis, 1000);
        $millis = $epochMillis % 1000;
        if ($millis < 0) {
            --$seconds;
            $millis += 1000;
        }
        return (new \DateTimeImmutable('@' . $seconds))
            ->setTimezone(new \DateTimeZone('+08:00'))
            ->format('YmdHis') . sprintf('.%03d', $millis);
    }

    public static function parse(string $timestamp): int
    {
        if (preg_match('/^[0-9]{14}\.[0-9]{3}$/D', $timestamp) !== 1) {
            throw new ResponseException('Invalid gateway timestamp');
        }
        $date = \DateTimeImmutable::createFromFormat('!YmdHis.v', $timestamp, new \DateTimeZone('+08:00'));
        if ($date === false || $date->format('YmdHis.v') !== $timestamp) {
            throw new ResponseException('Invalid gateway timestamp');
        }
        return $date->getTimestamp() * 1000 + (int) $date->format('v');
    }
}
