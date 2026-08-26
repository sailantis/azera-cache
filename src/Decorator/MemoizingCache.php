<?php

declare(strict_types=1);

namespace Azera\Cache\Decorator;

use Psr\SimpleCache\CacheInterface;

/**
 * Request-scoped memoizing decorator with a `remember()` convenience.
 *
 * Wraps any PSR-16 backend and adds a `remember($key, $ttl, $loader)`
 * helper that returns the cached value or invokes the loader, stores
 * the result, and returns it. When no backend is needed, the
 * `ArrayCache` from the framework is a fine default.
 *
 * The loader is only invoked on a cache miss, so warm keys have zero
 * loader overhead. There is no built-in locking here (use a Redis
 * `SETNX`-based decorator for cross-process stampede protection); the
 * goal is to avoid recomputation within a single process/request.
 */
class MemoizingCache implements CacheInterface
{
    public function __construct(
        private CacheInterface $inner,
    ) {}

    /**
     * Return the cached value or compute it via $loader, store, and return.
     *
     * @template T
     * @param callable(): T $loader
     * @return T
     */
    public function remember(string $key, int|\DateInterval|null $ttl, callable $loader): mixed
    {
        $value = $this->inner->get($key, $this->sentinel());
        if ($value !== $this->sentinel()) {
            return $value;
        }

        $value = $loader();
        $this->inner->set($key, $value, $ttl);
        return $value;
    }

    // --- PSR-16 delegation --------------------------------------------------

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($key, $default);
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        return $this->inner->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->inner->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        return $this->inner->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->inner->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    private function sentinel(): object
    {
        static $sentinel = null;
        return $sentinel ??= new \stdClass();
    }
}