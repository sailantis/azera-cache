<?php

declare(strict_types=1);

namespace Azera\Cache\Backend;

use Azera\Cache\InvalidArgumentException;

/**
 * Shared PSR-16 key validation.
 *
 * Mirrors the rules enforced by the framework's {@see \Azera\Cache\ArrayCache}:
 * non-empty, max 64 chars, alphanumeric + `._-`. Centralized so every
 * backend in this companion validates identically and throws the
 * framework's `InvalidArgumentException` (which implements the PSR-16
 * interface).
 */
trait ValidatesKeys
{
    /**
     * @throws InvalidArgumentException When the key is invalid.
     */
    private function assertKey(string $key): void
    {
        if ($key === '' || strlen($key) > 64) {
            throw new InvalidArgumentException(
                'Cache key must be non-empty and at most 64 characters.',
            );
        }

        if (!preg_match('/^[A-Za-z0-9._-]+$/', $key)) {
            throw new InvalidArgumentException(
                sprintf("Cache key '%s' contains invalid characters. Allowed: alphanumeric, '.', '_', '-'.", $key),
            );
        }
    }

    /**
     * Convert a TTL (int seconds / DateInterval / null) to a number of
     * seconds from now, or null for "no expiry".
     */
    private function ttlToSeconds(int|\DateInterval|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp() - time();
        }

        return $ttl;
    }
}