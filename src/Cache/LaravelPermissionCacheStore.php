<?php

namespace Tetranyble\Kinship\Cache;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Tetranyble\Kinship\Contracts\PermissionCacheStore;

final class LaravelPermissionCacheStore implements PermissionCacheStore
{
    public function __construct(private readonly Factory $cache) {}

    public function permissions(string $key): ?array
    {
        $value = $this->repository()->get($key);

        if (! is_array($value) || ($value['format'] ?? null) !== 1 || ! is_array($value['names'] ?? null)) {
            return null;
        }

        $names = [];
        foreach ($value['names'] as $name) {
            if (! is_string($name) || $name === '') {
                return null;
            }

            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    public function putPermissions(string $key, array $permissions, int $seconds): void
    {
        $this->repository()->put($key, [
            'format' => 1,
            'names' => array_values($permissions),
        ], $seconds);
    }

    public function versions(array $keys): array
    {
        $values = $this->repository()->getMultiple($keys);
        $versions = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $versions[$key] = is_string($value) && $value !== '' ? $value : '0';
        }

        foreach ($keys as $key) {
            $versions[$key] ??= '0';
        }

        return $versions;
    }

    public function rotate(string $key): void
    {
        $this->repository()->forever($key, bin2hex(random_bytes(16)));
    }

    private function repository(): Repository
    {
        $store = config('kinship.cache.store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }
}
