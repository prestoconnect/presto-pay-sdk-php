<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

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

    public function __construct(private readonly CacheItemPoolInterface $cache) {}

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
        $seenItem = $this->cache->getItem($this->key('seen.' . $eventRefNum));
        if ($seenItem->isHit()) {
            return false;
        }
        $seenItem->set(true)->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($seenItem);

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
