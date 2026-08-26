<?php

declare(strict_types=1);

namespace Azera\Cache\Tests;

use Azera\Cache\ArrayCache;
use Azera\Cache\Backend\ApcuCache;
use Azera\Cache\Backend\MemoryCache;
use Azera\Cache\Decorator\ChainCache;
use Azera\Cache\Decorator\TaggableCache;
use PHPUnit\Framework\TestCase;

final class NewBackendsTest extends TestCase
{
    // --- MemoryCache ---------------------------------------------------------

    public function test_memory_round_trip(): void
    {
        $cache = new MemoryCache();
        $cache->set('k', 'v');
        self::assertSame('v', $cache->get('k'));
        self::assertTrue($cache->has('k'));
    }

    public function test_memory_miss_returns_default(): void
    {
        $cache = new MemoryCache();
        self::assertSame('fallback', $cache->get('missing', 'fallback'));
    }

    public function test_memory_ttl_expiry(): void
    {
        $cache = new MemoryCache();
        $cache->set('temp', 'x', 1);
        self::assertSame('x', $cache->get('temp'));
        sleep(2);
        self::assertNull($cache->get('temp'));
        self::assertFalse($cache->has('temp'));
    }

    public function test_memory_delete_and_clear(): void
    {
        $cache = new MemoryCache();
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->delete('a');
        self::assertFalse($cache->has('a'));
        $cache->clear();
        self::assertFalse($cache->has('b'));
    }

    public function test_memory_lru_eviction_bounded(): void
    {
        $cache = new MemoryCache(maxEntries: 3);
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->set('c', 3);

        // Access 'a' so it becomes most-recent; then add 'd' pushing out 'b' (oldest).
        self::assertSame(1, $cache->get('a'));
        $cache->set('d', 4);

        self::assertFalse($cache->has('b'));
        self::assertSame(1, $cache->get('a'));
        self::assertSame(3, $cache->get('c'));
        self::assertSame(4, $cache->get('d'));
        self::assertSame(3, $cache->count());
    }

    public function test_memory_getMultiple_and_setMultiple(): void
    {
        $cache = new MemoryCache();
        $cache->setMultiple(['x' => 1, 'y' => 2]);
        $values = $cache->getMultiple(['x', 'y']);
        self::assertSame(1, $values['x']);
        self::assertSame(2, $values['y']);
    }

    public function test_memory_invalid_key_throws(): void
    {
        $cache = new MemoryCache();
        $this->expectException(\Azera\Cache\InvalidArgumentException::class);
        $cache->get('invalid key');
    }

    public function test_memory_invalid_max_entries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MemoryCache(maxEntries: 0);
    }

    // --- ApcuCache (guarded by extension availability) ----------------------

    public function test_apcu_round_trip(): void
    {
        if (!function_exists('apcu_fetch')) {
            self::markTestSkipped('ext-apcu not available.');
        }

        // APCu is often disabled in CLI (apc.enable_cli=Off); in that SAPI
        // apcu_store() silently no-ops. Detect usability rather than assume.
        $probe = 'azera_probe_' . bin2hex(random_bytes(4));
        if (!apcu_store($probe, 1)) {
            apcu_delete($probe);
            self::markTestSkipped('APCu not usable in this SAPI (apc.enable_cli likely Off).');
        }
        apcu_delete($probe);

        $cache = new ApcuCache('test_azera_');
        $cache->clear(); // isolate from prior runs

        try {
            $cache->set('k', 'v');
            self::assertSame('v', $cache->get('k'));
            self::assertTrue($cache->has('k'));

            $cache->set('expiring', 'x', 1);
            self::assertSame('x', $cache->get('expiring'));

            $cache->delete('k');
            self::assertNull($cache->get('k'));
        } finally {
            $cache->clear();
        }
    }

    // --- Regression: ChainCache backfill TTL --------------------------------

    public function test_chain_backfill_applies_ttl(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();
        // Backfill TTL of 1s; write source with a long TTL.
        $chain = new ChainCache([$l1, $l2], backfillTtl: 1);

        $l2->set('hot', 'value', 3600);
        self::assertSame('value', $chain->get('hot'));
        self::assertSame('value', $l1->get('hot'));

        // After the backfill TTL elapses, L1 should no longer report it as live.
        sleep(2);
        self::assertFalse($l1->has('hot'));
    }

    // --- Regression: TaggableCache index TTL --------------------------------

    public function test_taggable_index_outlives_short_value_ttl(): void
    {
        $inner  = new ArrayCache();
        $tagged = new TaggableCache($inner, indexTtl: 3600);

        // Short TTL on the value should NOT shorten the tag index lifetime.
        $tagged->set('user.1', 'Ada', 1, tags: ['users']);

        $tagKey = '__tag_._users';
        // Index entry must have the long indexTtl, not the value's short TTL.
        self::assertTrue($inner->has($tagKey));

        sleep(2);
        // Value is now expired, but the tag index must still exist so
        // invalidateTag can prune it / see the tagged key.
        self::assertTrue($inner->has($tagKey));
        self::assertNull($tagged->get('user.1'));
    }
}