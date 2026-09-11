<?php

declare(strict_types=1);

namespace Azera\Cache\Backend;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * APCu-backed PSR-16 cache.
 *
 * A zero-external-dependency backend for single-host workloads using the
 * PHP shared-memory user cache (`ext-apcu`). Values are stored serialized
 * so they survive across requests within the same shared cache. TTLs map
 * to APCu's native expiry.
 *
 * Note: APCu's memory is shared per SAPI instance, not host-wide. All
 * worker processes forked from a single SAPI master (one FPM pool, or
 * mod_php's Apache parent) share one segment, while each separate master
 * — and every CLI process — gets an isolated segment of its own. It is
 * *not* suitable for multi-server setups — use
 * {@see \Azera\Cache\Backend\RedisCache} or
 * {@see \Azera\Cache\Backend\MemcachedCache} for that. Within a single
 * SAPI segment it is the fastest persistent backend available.
 *
 * A key prefix is applied to avoid colliding with unrelated APCu entries
 * owned by other libraries sharing the same segment.
 */
class ApcuCache implements CacheInterface
{
    use ValidatesKeys;

    /**
     * @param string $prefix Optional key prefix applied to every APCu key.
     */
    public function __construct(
        private string $prefix = 'azera:',
    ) {
        if (!function_exists('apcu_fetch')) {
            throw new \RuntimeException('The APCu extension (ext-apcu) is required to use ApcuCache.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertKey($key);

        $success = false;
        $raw     = apcu_fetch($this->full($key), $success);
        if (!$success) {
            return $default;
        }

        $value = @unserialize($raw);
        return ($value === false && $raw !== serialize(false)) ? $default : $value;
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->assertKey($key);

        $full    = $this->full($key);
        $payload = serialize($value);
        $ttl     = $this->ttlToSeconds($ttl);

        if ($ttl !== null && $ttl <= 0) {
            apcu_delete($full);
            return true;
        }

        return (bool) apcu_store($full, $payload, $ttl ?? 0);
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        return (bool) apcu_delete($this->full($key));
    }

    public function clear(): bool
    {
        // Only delete keys bearing our prefix.
        $it = null;
        foreach (apcu_cache_info(false)['cache_list'] ?? [] as $entry) {
            $name = $entry['info'] ?? null;
            if (is_string($name) && str_starts_with($name, $this->prefix)) {
                apcu_delete($name);
            }
        }
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $full = [];
        foreach ($keys as $key) {
            $this->assertKey($key);
            $full[$this->full($key)] = $key;
        }

        if (empty($full)) {
            return [];
        }

        $values = apcu_fetch(array_keys($full));
        $result = [];
        foreach ($full as $fullKey => $originalKey) {
            if (!isset($values[$fullKey])) {
                $result[$originalKey] = $default;
                continue;
            }
            $raw   = $values[$fullKey];
            $value = @unserialize($raw);
            $result[$originalKey] = ($value === false && $raw !== serialize(false)) ? $default : $value;
        }
        return $result;
    }

    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        $ttl     = $this->ttlToSeconds($ttl);
        $payload = [];
        foreach ($values as $key => $value) {
            $this->assertKey((string) $key);
            $payload[$this->full((string) $key)] = serialize($value);
        }

        if (empty($payload)) {
            return true;
        }

        // Treat non-positive TTL as delete for each key.
        if ($ttl !== null && $ttl <= 0) {
            foreach (array_keys($payload) as $full) {
                apcu_delete($full);
            }
            return true;
        }

        return (bool) apcu_store($payload, null, $ttl ?? 0);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->assertKey($key);
            apcu_delete($this->full($key));
        }
        return true;
    }

    public function has(string $key): bool
    {
        $this->assertKey($key);
        return apcu_exists($this->full($key));
    }

    private function full(string $key): string
    {
        return $this->prefix . $key;
    }
}