<?php

declare(strict_types=1);

namespace Azera\Cache\Decorator;

use Psr\SimpleCache\CacheInterface;

/**
 * Tag-based invalidation on top of any PSR-16 backend.
 *
 * PSR-16 is tagless; this decorator adds the ability to store keys with
 * one or more tags and invalidate all keys bearing a given tag. A side
 * index (the set of keys per tag) is stored in the same wrapped backend
 * under a reserved prefix, so no extra storage is required.
 *
 * Example:
 * <code>
 * $cache = new TaggableCache($redis);
 * $cache->set('user.42', $user, tags: ['users', 'tenant_5']);
 * $cache->invalidateTag('users'); // evicts 'user.42' and every other tagged key
 * </code>
 */
class TaggableCache implements CacheInterface
{
    private const TAG_PREFIX = '__tag_._';

    /** @var CacheInterface */
    private CacheInterface $inner;

    /**
     * @param CacheInterface                $inner       The backend holding both values and tag indexes.
     * @param int|\DateInterval|null        $indexTtl    TTL for the tag side-index itself. Should be at
     *                                                   least as long as the longest value TTL you write,
     *                                                   otherwise the index can expire while the tagged
     *                                                   values are still valid and `invalidateTag()` would
     *                                                   silently miss them. Defaults to 30 days.
     */
    public function __construct(
        CacheInterface $inner,
        private int|\DateInterval|null $indexTtl = 2592000,
    ) {
        $this->inner = $inner;
    }

    /**
     * Store a value and associate it with $tags.
     *
     * @param string[] $tags
     */
    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null, array $tags = []): bool
    {
        $this->inner->set($key, $value, $ttl);

        foreach ($tags as $tag) {
            $this->addKeyToTag($tag, $key);
        }
        return true;
    }

    /**
     * Invalidate (delete) every key associated with $tag.
     */
    public function invalidateTag(string $tag): void
    {
        $keys = $this->tagKeys($tag);
        foreach ($keys as $key) {
            $this->inner->delete($key);
        }
        $this->inner->delete($this->tagKey($tag));
    }

    /**
     * Invalidate multiple tags at once.
     *
     * @param string[] $tags
     */
    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->invalidateTag($tag);
        }
    }

    // --- PSR-16 delegation --------------------------------------------------

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($key, $default);
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

    // --- tag index ---------------------------------------------------------

    private function tagKey(string $tag): string
    {
        return self::TAG_PREFIX . $tag;
    }

    /** @return string[] */
    private function tagKeys(string $tag): array
    {
        $raw = $this->inner->get($this->tagKey($tag), []);
        return is_array($raw) ? $raw : [];
    }

    private function addKeyToTag(string $tag, string $key): void
    {
        $keys = $this->tagKeys($tag);
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
        }
        // The index lives independently of the value's TTL (indexTtl), so
        // it stays valid long enough to invalidate all tagged keys even if
        // the last-set value had a very short TTL.
        $this->inner->set($this->tagKey($tag), $keys, $this->indexTtl);
    }
}