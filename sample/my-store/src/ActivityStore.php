<?php

declare(strict_types=1);

namespace PrestoUniverse\PrestoPay\Sample\MyStore;

/**
 * Recent-activity storage for the demo. Unlike the Go and Java samples, a PHP request does not share memory
 * with the next one -- the built-in server (and any other SAPI) tears down all script state when a request
 * ends -- so this keeps state in a small JSON file instead of an in-process map. A real merchant would use
 * its database's own tables and a unique constraint on `eventRefNum`, not a file.
 */
final class ActivityStore
{
    private const MAX_WEBHOOKS = 50;
    private const MAX_SEEN_EVENT_REFS = 500;

    public function __construct(private readonly string $path) {}

    /**
     * Runs $work with the store file locked for the duration, so a concurrent request cannot interleave a
     * read-modify-write. Returns whatever $work returns.
     *
     * @template T
     * @param callable(array{checkouts: array<string, array<string, mixed>>, webhooks: list<array<string, mixed>>, seenEventRefNums: list<string>}): T $work
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
                $data = ['checkouts' => [], 'webhooks' => [], 'seenEventRefNums' => []];
            }
            $data['checkouts'] ??= [];
            $data['webhooks'] ??= [];
            $data['seenEventRefNums'] ??= [];

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
     * Records a webhook delivery and reports whether this is the first time this eventRefNum has been seen.
     * Presto redelivers an unacknowledged webhook up to five times; acking a repeat with NotifyAck::Ok instead
     * of re-running fulfilment logic is what makes redelivery safe.
     *
     * @param array<string, mixed> $record
     */
    public function recordWebhook(string $eventRefNum, array $record): bool
    {
        return $this->withLock(function (array &$data) use ($eventRefNum, $record): bool {
            $firstDelivery = !in_array($eventRefNum, $data['seenEventRefNums'], true);
            if ($firstDelivery) {
                array_unshift($data['seenEventRefNums'], $eventRefNum);
                $data['seenEventRefNums'] = array_slice($data['seenEventRefNums'], 0, self::MAX_SEEN_EVENT_REFS);

                array_unshift($data['webhooks'], $record);
                $data['webhooks'] = array_slice($data['webhooks'], 0, self::MAX_WEBHOOKS);
            }
            return $firstDelivery;
        });
    }

    /** @return list<array<string, mixed>> */
    public function recentWebhooks(): array
    {
        return $this->withLock(static fn (array $data): array => $data['webhooks']);
    }
}
