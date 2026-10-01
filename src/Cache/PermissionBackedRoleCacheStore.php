<?php

namespace Tetranyble\Kinship\Cache;

use Tetranyble\Kinship\Contracts\PermissionCacheStore;
use Tetranyble\Kinship\Contracts\RoleCacheStore;

final class PermissionBackedRoleCacheStore implements RoleCacheStore
{
    public function __construct(private readonly PermissionCacheStore $cache) {}

    public function roles(string $key): ?array
    {
        return $this->cache->permissions($key);
    }

    public function putRoles(string $key, array $roles, int $seconds): void
    {
        $this->cache->putPermissions($key, $roles, $seconds);
    }
}
