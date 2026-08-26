<?php

declare(strict_types=1);

namespace Azera\Cache\Decorator;

use Psr\SimpleCache\CacheInterface;

/**
 * Layered read-through cache.
 *
 * Wraps multiple backends ordered fastest-first (e.g. `ArrayCache` →
 * `RedisCache` → `FileCache`). On a read, each layer is consulted in
 * order; the first hit is backfilled to all earlier (faster) layers.
 * Writes propagate to every layer (write-through). Deletes also
 * propagate to every layer.
 *
 * This gives you a fast in-process L1 with a persistent L2/L3 for
 * multi-process consistency, without stampede risk on warm keys.
 */
class ChainCache implements CacheInterface
{
    /** @var CacheInterface[] */
    private array $layers;

    /**
     * @param CacheInterface[]             $layers       Backends, fastest first.
     * @param int|\DateInterval|null       $backfillTtl  TTL applied when backfilling a hit into faster
     *                                                   layers. PSR-16 does not expose a stored entry's
     *                                                   TTL, so without this the backfilled copy would
     *                                                   never expire (stale-data risk). Pass the TTL you
     *                                                   write values with, or null to disable backfill TTL
     *                                                   (matches a "no expiry" source entry).
     */
    public function __construct(
        array $layers,
        private int|\DateInterval|null $backfillTtl = 300,
    ) {
        $this->layers = array_values($layers);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        foreach ($this->layers as $i => $layer) {
            $value = $layer->get($key, $this->sentinel());
            if ($value !== $this->sentinel()) {
                // Backfill earlier (faster) layers with a bounded TTL so a
                // backfilled copy does not outlive the source and go stale.
                for ($j = 0; $j < $i; $j++) {
                    $this->layers[$j]->set($key, $value, $this->backfillTtl);
                }
                return $value;
            }
        }
        return $default;
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        foreach ($this->layers as $layer) {
            $layer->set($key, $value, $ttl);
        }
        return true;
    }

    public function delete(string $key): bool
    {
        foreach ($this->layers as $layer) {
            $layer->delete($key);
        }
        return true;
    }

    public function clear(): bool
    {
        foreach ($this->layers as $layer) {
            $layer->clear();
        }
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
        foreach ($this->layers as $layer) {
            $layer->setMultiple($values, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($this->layers as $layer) {
            $layer->deleteMultiple($keys);
        }
        return true;
    }

    public function has(string $key): bool
    {
        foreach ($this->layers as $layer) {
            if ($layer->has($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A unique sentinel value used to distinguish "miss" from a stored null.
     */
    private function sentinel(): object
    {
        static $sentinel = null;
        return $sentinel ??= new \stdClass();
    }
}