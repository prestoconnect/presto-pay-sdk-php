<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PrestoUniverse\PrestoPay\PaymentStatus;

/**
 * Recent-activity storage for the demo, backed by Laravel's own Cache facade (the database driver by
 * default) rather than an in-process map: a plain PHP SDK sample keeps this in a JSON file under var/
 * because it has no framework-provided shared store, but Laravel already has one, so this uses it instead.
 * A real merchant would keep each order's status in its own database tables, not a general-purpose cache.
 */
final class ActivityStore
{
    private const MAX_WEBHOOKS = 50;
    private const CHECKOUT_TTL_SECONDS = 86400;
    private const ORDER_LOCK_SECONDS = 10;

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

    public function saveCheckout(string $txnRefNum, array $record): void
    {
        Cache::put('prestopay:checkout:' . $txnRefNum, $record, self::CHECKOUT_TTL_SECONDS);
    }

    public function findCheckout(string $txnRefNum): ?array
    {
        return Cache::get('prestopay:checkout:' . $txnRefNum);
    }

    /**
     * Applies a queried payment status to the order, finalising it only if it hasn't been finalised yet. The
     * return page and the webhook both call this, and Presto redelivers webhooks, so the read-then-write runs
     * under a cache lock; a real merchant makes it one conditional UPDATE on its orders table.
     */
    public function applyPaymentStatus(string $txnRefNum, string $next): void
    {
        $key = 'prestopay:order-status:' . $txnRefNum;
        $message = Cache::lock($key . ':lock', self::ORDER_LOCK_SECONDS)->block(
            self::ORDER_LOCK_SECONDS,
            function () use ($key, $txnRefNum, $next): string {
                $current = Cache::get($key);
                $message = self::describeChange($txnRefNum, $current, $next);
                if (self::canChangeStatus($current, $next)) {
                    Cache::put($key, $next, self::CHECKOUT_TTL_SECONDS);
                }
                return $message;
            },
        );
        Log::info($message);
    }

    /** @param array<string, mixed> $record */
    public function recordWebhook(array $record): void
    {
        // Not locked: this only feeds the display-only "recent webhooks" list, so a rare lost row under
        // concurrent deliveries is acceptable.
        $webhooks = Cache::get('prestopay:webhooks', []);
        array_unshift($webhooks, $record);
        Cache::forever('prestopay:webhooks', array_slice($webhooks, 0, self::MAX_WEBHOOKS));
    }

    /** @return list<array<string, mixed>> */
    public function recentWebhooks(): array
    {
        return Cache::get('prestopay:webhooks', []);
    }
}
