<?php

declare(strict_types=1);

namespace Azera\Cache\Tests;

use Azera\Cache\Backend\FileCache;
use Azera\Cache\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/azera_cache_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $this->rmrf($this->dir);
        }
    }

    public function test_set_and_get_round_trip(): void
    {
        $cache = new FileCache($this->dir);

        $cache->set('hello', 'world');
        self::assertSame('world', $cache->get('hello'));
        self::assertTrue($cache->has('hello'));
    }

    public function test_miss_returns_default(): void
    {
        $cache = new FileCache($this->dir);
        self::assertSame('fallback', $cache->get('missing', 'fallback'));
        self::assertFalse($cache->has('missing'));
    }

    public function test_ttl_expiry(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('temp', 'x', 1);

        self::assertSame('x', $cache->get('temp'));
        sleep(2);
        self::assertNull($cache->get('temp'));
    }

    public function test_delete(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('k', 'v');
        $cache->delete('k');
        self::assertFalse($cache->has('k'));
    }

    public function test_has_reports_false_after_expiry(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('temp', 'x', 1);

        self::assertTrue($cache->has('temp'));
        sleep(2);
        self::assertFalse($cache->has('temp'));
        self::assertNull($cache->get('temp'));
    }

    public function test_clear(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->clear();
        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    public function test_multiple_operations(): void
    {
        $cache = new FileCache($this->dir);
        $cache->setMultiple(['x' => 1, 'y' => 2]);

        $values = $cache->getMultiple(['x', 'y']);
        self::assertSame(1, $values['x']);
        self::assertSame(2, $values['y']);

        $cache->deleteMultiple(['x', 'y']);
        self::assertNull($cache->get('x'));
        self::assertNull($cache->get('y'));
    }

    public function test_invalid_key_throws(): void
    {
        $cache = new FileCache($this->dir);

        $this->expectException(InvalidArgumentException::class);
        $cache->get('invalid key with spaces');
    }

    public function test_empty_key_throws(): void
    {
        $cache = new FileCache($this->dir);

        $this->expectException(InvalidArgumentException::class);
        $cache->get('');
    }

    private function rmrf(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}