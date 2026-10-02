<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Sample\MyStore;

use PrestoUniverse\PrestoPay\PaymentStatus;

/**
 * Recent-activity storage for the demo. Unlike the Go and Java samples, a PHP request does not share memory
 * with the next one -- the built-in server (and any other SAPI) tears down all script state when a request
 * ends -- so this keeps state in a small JSON file instead of an in-process map. A real merchant would keep
 * each order's status in its own database, not a file.
 */
final class ActivityStore
{
    private const MAX_WEBHOOKS = 50;

    private const PAID_AND_STILL_OPEN = [
        PaymentStatus::AUTHORISED,
        PaymentStatus::PENDING_REVERSE,
        PaymentStatus::PENDING_REFUND,
        PaymentStatus::PARTIAL_REFUNDED,
    ];
    private const AFTER_PAYMENT = [
        ...self::PAID_AND_STILL_OPEN,
        PaymentStatus::REVERSED,
        PaymentStatus::REFUNDED,
    ];

    private static function canChangeStatus(?string $current, string $next): bool
    {
        if ($current === $next) {
            return false;
        }
        if ($current === null || $current === PaymentStatus::PENDING_AUTHORISE) {
            return true;
        }
        if (in_array($current, self::PAID_AND_STILL_OPEN, true)) {
            return in_array($next, self::AFTER_PAYMENT, true);
        }
        return false;
    }

    private static function describeChange(string $txnRefNum, ?string $current, string $next): string
    {
        if (!self::canChangeStatus($current, $next)) {
            return "Order txnRefNum={$txnRefNum} already finalised; {$next} changes nothing";
        }
        $paidNow = $next === PaymentStatus::AUTHORISED
            && ($current === null || $current === PaymentStatus::PENDING_AUTHORISE);
        return $paidNow
            ? "Order txnRefNum={$txnRefNum} paid; fulfilling it"
            : "Order txnRefNum={$txnRefNum} is now {$next}";
    }

    public function __construct(private readonly string $path) {}

    /**
     * Runs $work with the store file locked for the duration, so a concurrent request cannot interleave a
     * read-modify-write. Returns whatever $work returns.
     *
     * @template T
     * @param callable(array{checkouts: array<string, array<string, mixed>>, webhooks: list<array<string, mixed>>, orderStatuses: array<string, string>}): T $work
     * @return T
     */
    private function withLock(callable $work): mixed
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open activity store at ' . $this->path);
        }
        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            $data = $raw === '' || $raw === false ? null : json_decode($raw, true);
            if (!is_array($data)) {
                $data = ['checkouts' => [], 'webhooks' => [], 'orderStatuses' => []];
            }
            $data['checkouts'] ??= [];
            $data['webhooks'] ??= [];
            $data['orderStatuses'] ??= [];

            $result = $work($data);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($handle);
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $record */
    public function saveCheckout(string $txnRefNum, array $record): void
    {
        $this->withLock(function (array &$data) use ($txnRefNum, $record): void {
            $data['checkouts'][$txnRefNum] = $record;
        });
    }

    /** @return array<string, mixed>|null */
    public function findCheckout(string $txnRefNum): ?array
    {
        return $this->withLock(static fn (array $data): ?array => $data['checkouts'][$txnRefNum] ?? null);
    }

    /**
     * Applies a queried payment status to the order, finalising it only if it hasn't been finalised yet. The
     * return page and the webhook both call this, and Presto redelivers webhooks, so it runs under the file
     * lock; a real merchant makes it one conditional UPDATE on its orders table.
     */
    public function applyPaymentStatus(string $txnRefNum, string $next): void
    {
        $message = $this->withLock(function (array &$data) use ($txnRefNum, $next): string {
            $current = $data['orderStatuses'][$txnRefNum] ?? null;
            $message = self::describeChange($txnRefNum, $current, $next);
            if (self::canChangeStatus($current, $next)) {
                $data['orderStatuses'][$txnRefNum] = $next;
            }
            return $message;
        });
        error_log($message);
    }

    /** @param array<string, mixed> $record */
    public function recordWebhook(array $record): void
    {
        $this->withLock(function (array &$data) use ($record): void {
            array_unshift($data['webhooks'], $record);
            $data['webhooks'] = array_slice($data['webhooks'], 0, self::MAX_WEBHOOKS);
        });
    }

    /** @return list<array<string, mixed>> */
    public function recentWebhooks(): array
    {
        return $this->withLock(static fn (array $data): array => $data['webhooks']);
    }
}
