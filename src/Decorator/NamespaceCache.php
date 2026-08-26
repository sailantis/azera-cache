<?php

declare(strict_types=1);

namespace Azera\Cache\Decorator;

use Psr\SimpleCache\CacheInterface;

/**
 * Key-prefix decorator for multi-tenant isolation.
 *
 * Wraps any PSR-16 backend and transparently prefixes every key. Useful
 * when multiple tenants share a single cache backend (e.g. a Redis
 * instance) and must not collide. The prefix is applied after the
 * wrapped backend's own validation, so the wrapped backend sees keys
 * like `tenant_42:user_profile` — but callers work with `user_profile`.
 *
 * Note: the framework validates keys to alphanumeric + `._-`; a prefix
 * containing other characters (e.g. `_`) is fine because the *combined*
 * key is what the wrapped backend validates. Keep prefixes to allowed
 * chars to stay safe.
 */
class NamespaceCache implements CacheInterface
{
    public function __construct(
        private CacheInterface $inner,
        private string $prefix,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($this->full($key), $default);
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        return $this->inner->set($this->full($key), $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($this->full($key));
    }

    public function clear(): bool
    {
        // PSR-16 clear() is global; we cannot safely clear only the
        // prefixed keys on a generic backend. Delegates to the inner
        // backend, which may clear more than intended. Document this
        // carefully for callers.
        return $this->inner->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $mapped = [];
        foreach ($keys as $key) {
            $mapped[$this->full($key)] = $key;
        }

        $values = $this->inner->getMultiple(array_keys($mapped), $default);
        $result = [];
        foreach ($values as $full => $value) {
            $key = $mapped[$full] ?? $full;
            $result[$key] = $value;
        }
        return $result;
    }

    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        $mapped = [];
        foreach ($values as $key => $value) {
            $mapped[$this->full((string) $key)] = $value;
        }
        return $this->inner->setMultiple($mapped, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $mapped = [];
        foreach ($keys as $key) {
            $mapped[] = $this->full($key);
        }
        return $this->inner->deleteMultiple($mapped);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($this->full($key));
    }

    private function full(string $key): string
    {
        return $this->prefix . $key;
    }
}