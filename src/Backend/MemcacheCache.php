<?php

declare(strict_types=1);

namespace Azera\Cache\Backend;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * Memcached-backed PSR-16 cache using `ext-memcache` (the legacy `Memcache` class).
 *
 * Requires a running memcached server (external program) and the
 * `ext-memcache` PHP extension. This uses the older `Memcache` API, which
 * predates the `Memcached` class (see {@see \Azera\Cache\Backend\MemcachedCache}
 * for the recommended `Memcached` adapter). Prefer `MemcachedCache` when
 * both extensions are available; use this class when only `ext-memcache`
 * is installed.
 *
 * Values are serialized and stored in the memcached server. TTLs map to
 * memcached's native expiry.
 */
class MemcacheCache implements CacheInterface
{
    use ValidatesKeys;

    /**
     * @param \Memcache $memcache A connected memcache client.
     * @param string    $prefix   Optional key prefix applied before serialization-safe chars.
     */
    public function __construct(
        private \Memcache $memcache,
        private string $prefix = 'azera:',
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertKey($key);

        $raw = $this->memcache->get($this->full($key));
        if ($raw === false) {
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
            $this->memcache->delete($full);
            return true;
        }

        return $this->memcache->set($full, $payload, 0, $ttl ?? 0);
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        return $this->memcache->delete($this->full($key));
    }

    public function clear(): bool
    {
        // flush() wipes the entire server, unsafe on a shared host. The
        // legacy memcache API offers no prefix-scoped clear.
        return false;
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

        $values = $this->memcache->get(array_keys($full));
        if (!is_array($values)) {
            $values = [];
        }

        $result = [];
        foreach ($full as $fullKey => $originalKey) {
            if (!array_key_exists($fullKey, $values)) {
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

        if ($ttl !== null && $ttl <= 0) {
            foreach (array_keys($payload) as $full) {
                $this->memcache->delete($full);
            }
            return true;
        }

        foreach ($payload as $full => $data) {
            $this->memcache->set($full, $data, 0, $ttl ?? 0);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->assertKey($key);
            $this->memcache->delete($this->full($key));
        }
        return true;
    }

    public function has(string $key): bool
    {
        $this->assertKey($key);
        return $this->memcache->get($this->full($key)) !== false;
    }

    private function full(string $key): string
    {
        return $this->prefix . $key;
    }
}