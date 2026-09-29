<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Recent-activity storage for the demo, backed by Laravel's own Cache facade (the database driver by
 * default) rather than an in-process map: a plain PHP SDK sample keeps this in a JSON file under var/
 * because it has no framework-provided shared store, but Laravel already has one, so this uses it instead.
 * A real merchant would use its own database tables with a unique constraint on `eventRefNum`, not a
 * general-purpose cache.
 */
final class ActivityStore
{
    private const MAX_WEBHOOKS = 50;
    private const CHECKOUT_TTL_SECONDS = 86400;

    public function saveCheckout(string $txnRefNum, array $record): void
    {
        Cache::put('prestopay:checkout:' . $txnRefNum, $record, self::CHECKOUT_TTL_SECONDS);
    }

    public function findCheckout(string $txnRefNum): ?array
    {
        return Cache::get('prestopay:checkout:' . $txnRefNum);
    }

    /**
     * Records a webhook delivery and reports whether this is the first time this eventRefNum has been seen.
     * Presto redelivers an unacknowledged webhook up to five times; acking a repeat with NotifyAck::Ok
     * instead of re-running fulfilment logic is what makes redelivery safe.
     */
    public function recordWebhook(string $eventRefNum, array $record): bool
    {
        // Cache::add() is atomic (fails if the key already exists), which is what makes this dedupe check
        // safe under concurrent deliveries of the same eventRefNum -- the correctness guarantee this method
        // exists for. The read-modify-write below it is not atomic, but it only affects the display-only
        // "recent webhooks" list, so a rare lost row under concurrent *different* eventRefNums is acceptable.
        $firstDelivery = Cache::add('prestopay:seen:' . $eventRefNum, true, self::CHECKOUT_TTL_SECONDS);
        if ($firstDelivery) {
            $webhooks = Cache::get('prestopay:webhooks', []);
            array_unshift($webhooks, $record);
            Cache::forever('prestopay:webhooks', array_slice($webhooks, 0, self::MAX_WEBHOOKS));
        }
        return $firstDelivery;
    }

    /** @return list<array<string, mixed>> */
    public function recentWebhooks(): array
    {
        return Cache::get('prestopay:webhooks', []);
    }
}
