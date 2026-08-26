<?php

declare(strict_types=1);

namespace Azera\Cache\Tests;

use Azera\Cache\Decorator\ChainCache;
use Azera\Cache\Decorator\MemoizingCache;
use Azera\Cache\Decorator\NamespaceCache;
use Azera\Cache\Decorator\TaggableCache;
use PHPUnit\Framework\TestCase;
use Azera\Cache\ArrayCache;

final class DecoratorsTest extends TestCase
{
    public function test_chain_read_through_and_backfill(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $chain = new ChainCache([$l1, $l2]);
        $l2->set('hot', 'value');

        self::assertSame('value', $chain->get('hot'));
        // L1 should now be backfilled.
        self::assertSame('value', $l1->get('hot'));
    }

    public function test_chain_write_through(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $chain = new ChainCache([$l1, $l2]);
        $chain->set('k', 'v');

        self::assertSame('v', $l1->get('k'));
        self::assertSame('v', $l2->get('k'));
    }

    public function test_chain_delete_propagates(): void
    {
        $l1    = new ArrayCache();
        $l2    = new ArrayCache();
        $chain = new ChainCache([$l1, $l2]);
        $chain->set('k', 'v');
        $chain->delete('k');

        self::assertFalse($l1->has('k'));
        self::assertFalse($l2->has('k'));
    }

    public function test_namespace_prefixes_keys(): void
    {
        $inner      = new ArrayCache();
        $namespaced = new NamespaceCache($inner, 'tenant_42_');

        $namespaced->set('key', 'value');
        self::assertSame('value', $inner->get('tenant_42_key'));
        self::assertSame('value', $namespaced->get('key'));
        self::assertTrue($namespaced->has('key'));
    }

    public function test_namespace_delete(): void
    {
        $inner      = new ArrayCache();
        $namespaced = new NamespaceCache($inner, 't_');
        $namespaced->set('k', 'v');
        $namespaced->delete('k');

        self::assertFalse($inner->has('t_k'));
    }

    public function test_taggable_invalidate_tag(): void
    {
        $inner  = new ArrayCache();
        $tagged = new TaggableCache($inner);

        $tagged->set('user.1', 'Ada', tags: ['users']);
        $tagged->set('user.2', 'Grace', tags: ['users']);
        $tagged->set('post.1', 'Hello', tags: ['posts']);

        $tagged->invalidateTag('users');

        self::assertNull($tagged->get('user.1'));
        self::assertNull($tagged->get('user.2'));
        self::assertSame('Hello', $tagged->get('post.1'));
    }

    public function test_memoize_remember_caches_loader_result(): void
    {
        $memo  = new MemoizingCache(new ArrayCache());
        $calls = 0;

        $loader = function () use (&$calls) {
            $calls++;
            return 'computed';
        };

        self::assertSame('computed', $memo->remember('k', 60, $loader));
        self::assertSame('computed', $memo->remember('k', 60, $loader));
        self::assertSame(1, $calls);
    }

    public function test_memoize_remember_distinct_from_null(): void
    {
        $memo   = new MemoizingCache(new ArrayCache());
        $calls  = 0;
        $loader = function () use (&$calls) {
            $calls++;
            return null;
        };

        self::assertNull($memo->remember('nullval', 60, $loader));
        self::assertNull($memo->remember('nullval', 60, $loader));
        self::assertSame(1, $calls);
    }
}