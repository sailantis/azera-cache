<?php

declare(strict_types=1);

namespace Azera\Cache\Backend;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * Memcached-backed PSR-16 cache using `ext-memcached` (the `Memcached` class).
 *
 * A production-grade adapter for multi-server and multi-process caching.
 * Requires a running memcached server (external program) and the
 * `ext-memcached` PHP extension. The server is injected so connection
 * configuration (host, port, persistent pool, SASL auth) is the caller's
 * responsibility; this class is a pure cache adapter.
 *
 * Values are serialized and stored in the memcached server. TTLs map to
 * memcached's native expiry (max 30 days server-side; longer TTLs are
 * clamped, so prefer Redis for very long-lived keys).
 */
class MemcachedCache implements CacheInterface
{
    use ValidatesKeys;

    /**
     * @param \Memcached $memcached A configured, connected memcached client.
     * @param string     $prefix    Optional key prefix applied before serialization-safe chars.
     */
    public function __construct(
        private \Memcached $memcached,
        private string $prefix = 'azera:',
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertKey($key);

        $raw = $this->memcached->get($this->full($key));
        if ($raw === false && $this->memcached->getResultCode() !== \Memcached::RES_SUCCESS) {
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
            $this->memcached->delete($full);
            return true;
        }

        // Memcached uses unix-time for TTLs > 30 days; we always pass seconds
        // from now, which is what set() expects for TTL <= 30 days.
        return $this->memcached->set($full, $payload, $ttl ?? 0);
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        return $this->memcached->delete($this->full($key));
    }

    public function clear(): bool
    {
        // memcached has no prefix-scoped clear; flush() wipes the whole
        // server which is unsafe on a shared host. Return false and let
        // callers decide. Deleting only our keys would require key
        // enumeration (getAllKeys), which is expensive and unreliable on
        // large deployments.
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

        $values = $this->memcached->getMulti(array_keys($full));
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
            $this->memcached->deleteMulti(array_keys($payload));
            return true;
        }

        return $this->memcached->setMulti($payload, $ttl ?? 0);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $full = [];
        foreach ($keys as $key) {
            $this->assertKey($key);
            $full[] = $this->full($key);
        }
        if (empty($full)) {
            return true;
        }
        $this->memcached->deleteMulti($full);
        return true;
    }

    public function has(string $key): bool
    {
        $this->assertKey($key);
        return $this->memcached->get($this->full($key)) !== false;
    }

    private function full(string $key): string
    {
        return $this->prefix . $key;
    }
}