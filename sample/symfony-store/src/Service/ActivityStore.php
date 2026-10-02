<?php

declare(strict_types=1);

namespace App\Service;

use PrestoUniverse\PrestoPay\PaymentStatus;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Recent-activity storage for the demo, backed by Symfony's own cache.app pool (filesystem by default --
 * see config/packages/cache.yaml) rather than an in-process map: a plain PHP SDK sample keeps this in a
 * JSON file under var/ because it has no framework-provided shared store, but Symfony already has one, so
 * this uses it instead. A real merchant would keep each order's status in its own database tables, not a
 * general-purpose cache.
 */
final class ActivityStore
{
    private const MAX_WEBHOOKS = 50;
    private const TTL_SECONDS = 86400;

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

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
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
     * Applies a queried payment status to the order, finalising it only if it hasn't been finalised yet. The
     * return page and the webhook both call this, and Presto redelivers webhooks, so a plain read-then-save
     * would let two requests both finalise the order; the lock makes it atomic across requests. A real
     * merchant makes it one conditional UPDATE on its orders table.
     */
    public function applyPaymentStatus(string $txnRefNum, string $next): void
    {
        $lock = $this->lockFactory->createLock('prestopay-order-status-' . $txnRefNum, self::TTL_SECONDS);
        $lock->acquire(true);
        try {
            $item = $this->cache->getItem($this->key('order-status.' . $txnRefNum));
            $current = $item->isHit() ? $item->get() : null;
            $message = self::describeChange($txnRefNum, $current, $next);
            if (self::canChangeStatus($current, $next)) {
                $item->set($next)->expiresAfter(self::TTL_SECONDS);
                $this->cache->save($item);
            }
        } finally {
            $lock->release();
        }
        $this->logger->info($message);
    }

    /** @param array<string, mixed> $record */
    public function recordWebhook(array $record): void
    {
        // Not locked: this only feeds the display-only "recent webhooks" list, so a rare lost row under
        // concurrent deliveries is acceptable.
        $webhooksItem = $this->cache->getItem($this->key('webhooks'));
        $webhooks = $webhooksItem->isHit() ? $webhooksItem->get() : [];
        array_unshift($webhooks, $record);
        $webhooksItem->set(array_slice($webhooks, 0, self::MAX_WEBHOOKS));
        $this->cache->save($webhooksItem);
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
