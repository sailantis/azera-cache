# azera-cache

Cache companion for the [Azera framework](../azera-framework).

The framework ships PSR-16 dev/null implementations (`ArrayCache`, `NullCache`, `InvalidArgumentException`). This package provides the production-grade backends the docs defer (`docs/14-CACHE.md`):

- `Azera\Cache\Backend\RedisCache` — `ext-redis` adapter with pipelined batch operations.
- `Azera\Cache\Backend\FileCache` — sharded, atomic-write file cache (no extension needed).
- `Azera\Cache\Backend\ApcuCache` — `ext-apcu` single-host shared-memory adapter (no external program needed).
- `Azera\Cache\Backend\MemcachedCache` — `ext-memcached` adapter (requires a running memcached server).
- `Azera\Cache\Backend\MemcacheCache` — `ext-memcache` (legacy `Memcache` class) adapter (requires a running memcached server).
- `Azera\Cache\Backend\MemoryCache` — bounded, array-backed cache with LRU eviction for long-running daemons.
- `Azera\Cache\Decorator\ChainCache` — layered read-through with write-through + stampede protection.
- `Azera\Cache\Decorator\TaggableCache` — tag-based invalidation on top of any PSR-16 backend.
- `Azera\Cache\Decorator\NamespaceCache` — key-prefix decorator for multi-tenant isolation.
- `Azera\Cache\Decorator\MemoizingCache` — `remember($key, $ttl, $loader)` with optional locking.

> **Note on external programs:** `MemcachedCache` and `MemcacheCache` both require a running **memcached server** in addition to the PHP extension. `RedisCache` requires a Redis server. `ApcuCache` and `MemoryCache` and `FileCache` need nothing extra.

All backends implement `\Psr\SimpleCache\CacheInterface` directly (per `patterns.md`: depend on the PSR, no framework-specific cache interface) and reuse the framework's `Azera\Cache\InvalidArgumentException` for key validation.

## Installation

```json
{
    "repositories": [{ "type": "path", "url": "../azera-cache" }],
    "require": { "sailantis/azera-cache": "dev-main" }
}
```

## License

MIT.