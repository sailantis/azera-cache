<?php

declare(strict_types=1);

namespace Azera\Cache\Backend;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\CacheException;
use RuntimeException;

/**
 * Redis-backed PSR-16 cache using `ext-redis`.
 *
 * A production-grade adapter for multi-process (PHP-FPM) and
 * multi-server caching. TTLs map to Redis `SETEX` (or `SET` with `EX`
 * for pipelined batches). Batch operations use Redis pipelining for
 * efficiency. Key validation reuses the shared trait so the same
 * rules as the framework's `ArrayCache` apply.
 *
 * The Redis client (`\Redis` instance) is injected so connection
 * configuration (persistent id, auth, db index) is the caller's
 * responsibility. This class is a pure cache adapter.
 */
class RedisCache implements CacheInterface
{
    use ValidatesKeys;

    /**
     * @param \Redis $redis    A connected ext-redis client.
     * @param string $prefix   Optional key prefix (applied before validation-safe chars).
     */
    public function __construct(
        private \Redis $redis,
        private string $prefix = 'azera:',
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertKey($key);
        $raw = $this->redis->get($this->full($key));

        if ($raw === false) {
            return $default;
        }

        $value = @unserialize($raw);
        return $value === false && $raw !== serialize(false) ? $default : $value;
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->assertKey($key);
        $full    = $this->full($key);
        $payload = serialize($value);
        $seconds = $this->ttlToSeconds($ttl);

        if ($seconds === null) {
            return $this->redis->set($full, $payload);
        }

        if ($seconds <= 0) {
            $this->redis->del($full);
            return true;
        }

        return $this->redis->setex($full, $seconds, $payload);
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        return $this->redis->del($this->full($key)) >= 0;
    }

    public function clear(): bool
    {
        // Only clear keys with our prefix to avoid wiping unrelated data.
        $it      = null;
        $deleted = 0;
        while ($keys = $this->redis->scan($it, $this->prefix . '*', 1000)) {
            if (!empty($keys)) {
                $deleted += $this->redis->del($keys);
            }
        }
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $mapped   = [];
        $original = [];
        foreach ($keys as $key) {
            $this->assertKey($key);
            $full = $this->full($key);
            $mapped[] = $full;
            $original[$full] = $key;
        }

        if (empty($mapped)) {
            return [];
        }

        $values = $this->redis->mget($mapped);
        $result = [];
        foreach ($values as $i => $raw) {
            $full = $mapped[$i];
            $key  = $original[$full];
            if ($raw === false) {
                $result[$key] = $default;
                continue;
            }
            $value = @unserialize($raw);
            $result[$key] = ($value === false && $raw !== serialize(false)) ? $default : $value;
        }
        return $result;
    }

    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        $seconds = $this->ttlToSeconds($ttl);
        $payload = [];
        foreach ($values as $key => $value) {
            $this->assertKey((string) $key);
            $payload[$this->full((string) $key)] = serialize($value);
        }

        if (empty($payload)) {
            return true;
        }

        $this->redis->multi();
        foreach ($payload as $full => $data) {
            if ($seconds === null) {
                $this->redis->set($full, $data);
            } elseif ($seconds > 0) {
                $this->redis->setex($full, $seconds, $data);
            } else {
                $this->redis->del($full);
            }
        }
        $this->redis->exec();
        return true;
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
        return $this->redis->del($full) >= 0;
    }

    public function has(string $key): bool
    {
        $this->assertKey($key);
        return (bool) $this->redis->exists($this->full($key));
    }

    private function full(string $key): string
    {
        return $this->prefix . $key;
    }
}