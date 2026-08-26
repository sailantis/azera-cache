<?php

declare(strict_types=1);

namespace Azera\Cache\Backend;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * Bounded in-process cache with LRU eviction.
 *
 * Like the framework's `ArrayCache`, values are kept as live references
 * (no serialization overhead). Unlike `ArrayCache`, it is *bounded*: when
 * the entry count exceeds {@see $maxEntries}, the least-recently-used
 * entries are evicted. This makes it a safe L1 for long-running daemons
 * (RoadRunner workers, queue workers) where an unbounded array would grow
 * the heap without bound.
 *
 * Use as the fastest layer in a {@see \Azera\Cache\Decorator\ChainCache}:
 * <code>
 * $cache = new ChainCache([
 *     new MemoryCache(maxEntries: 5000),
 *     new \Azera\Cache\Backend\RedisCache($redis),
 * ]);
 * </code>
 */
class MemoryCache implements CacheInterface
{
    use ValidatesKeys;

    /**
     * @param int $maxEntries Maximum number of live entries before LRU eviction.
     */
    public function __construct(
        private int $maxEntries = 1024,
    ) {
        if ($maxEntries < 1) {
            throw new \InvalidArgumentException('MemoryCache maxEntries must be at least 1.');
        }
    }

    /** @var array<string, array{value: mixed, expires: int|null, used: int}> */
    private array $data = [];

    /** Monotonic access counter for LRU ordering. */
    private int $clock = 0;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertKey($key);

        if (!isset($this->data[$key])) {
            return $default;
        }

        $entry = $this->data[$key];

        if ($entry['expires'] !== null && $entry['expires'] <= time()) {
            unset($this->data[$key]);
            return $default;
        }

        $entry['used'] = ++$this->clock;
        $this->data[$key] = $entry;

        return $entry['value'];
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->assertKey($key);

        $expires = $this->expiryToTimestamp($ttl);

        if ($expires !== null && $expires <= time()) {
            // Already expired; treat as a delete.
            unset($this->data[$key]);
            return true;
        }

        $this->data[$key] = [
            'value'   => $value,
            'expires' => $expires,
            'used'    => ++$this->clock,
        ];

        $this->evict();
        return true;
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        unset($this->data[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->data  = [];
        $this->clock = 0;
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        $this->assertKey($key);

        if (!isset($this->data[$key])) {
            return false;
        }

        $entry = $this->data[$key];

        if ($entry['expires'] !== null && $entry['expires'] <= time()) {
            unset($this->data[$key]);
            return false;
        }

        return true;
    }

    /**
     * Remove all expired entries. Not required for correctness (expired
     * entries are lazily purged on access), but keeps the LRU index
     * compact for long-running daemons. Call periodically.
     */
    public function gc(): void
    {
        $now = time();
        foreach ($this->data as $key => $entry) {
            if ($entry['expires'] !== null && $entry['expires'] <= $now) {
                unset($this->data[$key]);
            }
        }
    }

    /** @return int Current number of live entries. */
    public function count(): int
    {
        return count($this->data);
    }

    // --- internals -----------------------------------------------------------

    private function evict(): void
    {
        $count = count($this->data);
        if ($count <= $this->maxEntries) {
            return;
        }

        $excess = $count - $this->maxEntries;

        // Stable sort so oldest (smallest 'used') goes first, keyed for determinism.
        uasort(
            $this->data,
            fn(array $a, array $b): int => $a['used'] <=> $b['used'],
        );

        $i = 0;
        foreach (array_keys($this->data) as $key) {
            if ($i >= $excess) {
                break;
            }
            unset($this->data[$key]);
            $i++;
        }
    }

    private function expiryToTimestamp(int|\DateInterval|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp();
        }
        if ($ttl <= 0) {
            return 0;
        }
        return time() + $ttl;
    }
}