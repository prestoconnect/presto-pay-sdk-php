<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Recent-activity storage for the demo, backed by Symfony's own cache.app pool (filesystem by default --
 * see config/packages/cache.yaml) rather than an in-process map: a plain PHP SDK sample keeps this in a
 * JSON file under var/ because it has no framework-provided shared store, but Symfony already has one, so
 * this uses it instead. A real merchant would use its own database tables with a unique constraint on
 * `eventRefNum`, not a general-purpose cache.
 */
final class ActivityStore
{
    private const MAX_WEBHOOKS = 50;
    private const TTL_SECONDS = 86400;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LockFactory $lockFactory,
    ) {}

    /** @param array<string, mixed> $record */
    public function saveCheckout(string $txnRefNum, array $record): void
    {
        $item = $this->cache->getItem($this->key('checkout.' . $txnRefNum));
        $item->set($record)->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    /** @return array<string, mixed>|null */
    public function findCheckout(string $txnRefNum): ?array
    {
        $item = $this->cache->getItem($this->key('checkout.' . $txnRefNum));
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Records a webhook delivery and reports whether this is the first time this eventRefNum has been seen.
     * Presto redelivers an unacknowledged webhook up to five times; acking a repeat with NotifyAck::Ok
     * instead of re-running fulfilment logic is what makes redelivery safe.
     *
     * @param array<string, mixed> $record
     */
    public function recordWebhook(string $eventRefNum, array $record): bool
    {
        // A plain isHit()-then-save() here would race: two concurrent deliveries of the same eventRefNum
        // could both observe a miss before either writes, and both would (wrongly) run fulfilment. The lock
        // makes the check-and-set atomic across requests, not just within one.
        $lock = $this->lockFactory->createLock('prestopay-webhook-seen-' . $eventRefNum, self::TTL_SECONDS);
        if (!$lock->acquire()) {
            return false;
        }
        try {
            $seenItem = $this->cache->getItem($this->key('seen.' . $eventRefNum));
            if ($seenItem->isHit()) {
                return false;
            }
            $seenItem->set(true)->expiresAfter(self::TTL_SECONDS);
            $this->cache->save($seenItem);
        } finally {
            $lock->release();
        }

        // Not locked: this read-modify-write only affects the display-only "recent webhooks" list, so a
        // rare lost row under concurrent *different* eventRefNums is acceptable, unlike the dedupe above.
        $webhooksItem = $this->cache->getItem($this->key('webhooks'));
        $webhooks = $webhooksItem->isHit() ? $webhooksItem->get() : [];
        array_unshift($webhooks, $record);
        $webhooksItem->set(array_slice($webhooks, 0, self::MAX_WEBHOOKS));
        $this->cache->save($webhooksItem);

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function recentWebhooks(): array
    {
        $item = $this->cache->getItem($this->key('webhooks'));
        return $item->isHit() ? $item->get() : [];
    }

    private function key(string $suffix): string
    {
        // PSR-6 keys forbid {}()/\@: -- our suffixes are gateway/our-own refs, but sanitise defensively.
        return 'prestopay.' . preg_replace('/[^A-Za-z0-9_.]/', '_', $suffix);
    }
}
