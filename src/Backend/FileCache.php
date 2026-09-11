<?php

declare(strict_types=1);

namespace Azera\Cache\Backend;

use Azera\Cache\InvalidArgumentException;
use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * File-based PSR-16 cache.
 *
 * The zero-extension production default for shared hosts. Values are
 * serialized and stored in sharded directories (`ab/cdef`) to keep
 * large key counts scannable. Writes are atomic (`tempnam` + `rename`)
 * so concurrent `set()` calls never corrupt a file. Each entry stores a
 * header with the expiry timestamp; expired entries are purged lazily
 * on read and opportunistically by {@see gc()}.
 */
class FileCache implements CacheInterface
{
    use ValidatesKeys;

    private const PAYLOAD_VERSION = 1;

    /**
     * @param string $directory   Cache root directory (created if missing).
     * @param int    $dirShard    Number of leading characters used for sharding (default 2).
     */
    public function __construct(
        private string $directory,
        private int $dirShard = 2,
    ) {
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertKey($key);
        $path = $this->path($key);

        if (!is_file($path)) {
            return $default;
        }

        $entry = $this->read($path);
        if ($entry === null) {
            return $default;
        }

        if ($entry['expires'] !== null && $entry['expires'] <= time()) {
            @unlink($path);
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->assertKey($key);

        $dir = $this->dir($key);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $path    = $this->path($key);
        $payload = serialize([
            'v'       => self::PAYLOAD_VERSION,
            'expires' => $this->expiryToTimestamp($ttl),
            'value'   => $value,
        ]);

        // Atomic write: tempnam in the same dir + rename.
        $tmp = @tempnam($dir, 'azera_');
        if ($tmp === false) {
            throw new InvalidArgumentException(sprintf('Cannot create temporary file in %s.', $dir));
        }

        if (@file_put_contents($tmp, $payload) === false) {
            @unlink($tmp);
            throw new InvalidArgumentException(sprintf('Cannot write cache file %s.', $path));
        }

        // Windows rename() does not overwrite an existing destination, so an
        // overwrite must remove the stale entry first (the @-suppressed
        // unlink is a no-op when the file does not exist).
        if (is_file($path)) {
            @unlink($path);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new InvalidArgumentException(sprintf('Cannot move cache file to %s.', $path));
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        $path = $this->path($key);

        if (is_file($path)) {
            return @unlink($path);
        }
        return true;
    }

    public function clear(): bool
    {
        $this->rmrf($this->directory);
        @mkdir($this->directory, 0775, true);
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        $this->assertKey($key);
        $path = $this->path($key);

        if (!is_file($path)) {
            return false;
        }

        $entry = $this->read($path);
        if ($entry === null) {
            return false;
        }

        if ($entry['expires'] !== null && $entry['expires'] <= time()) {
            @unlink($path);
            return false;
        }

        return true;
    }

    /**
     * Garbage-collect expired entries. Walks the cache directory and
     * removes files whose expiry has passed. Call periodically (e.g.
     * from a cron task).
     */
    public function gc(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $now      = time();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $entry = $this->read($file->getPathname());
            if ($entry !== null && $entry['expires'] !== null && $entry['expires'] <= $now) {
                @unlink($file->getPathname());
            }
        }
    }

    // --- internals -----------------------------------------------------------

    /**
     * @return array{expires: int|null, value: mixed}|null
     */
    private function read(string $path): ?array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $entry = @unserialize($contents);
        if (!is_array($entry) || !array_key_exists('value', $entry)) {
            return null;
        }

        return $entry;
    }

    private function dir(string $key): string
    {
        $shard = substr($key, 0, $this->dirShard);

        return $this->directory . DIRECTORY_SEPARATOR . $shard;
    }

    private function path(string $key): string
    {
        return $this->dir($key) . DIRECTORY_SEPARATOR . $key . '.cache';
    }

    private function expiryToTimestamp(int|\DateInterval|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp();
        }
        if ($ttl <= 0) {
            return 0;
        }
        return time() + $ttl;
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($dir);
    }
}