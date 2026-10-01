<?php

namespace Tetranyble\Kinship\Roles;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Tetranyble\Kinship\Cache\PermissionCacheKeys;
use Tetranyble\Kinship\Contracts\EffectiveRoleResolver;
use Tetranyble\Kinship\Contracts\PermissionCacheStore;
use Tetranyble\Kinship\Contracts\RoleCacheStore;
use Tetranyble\Kinship\Support\AuthorizationContext;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;
use Throwable;

final class CachedEffectiveRoleResolver implements EffectiveRoleResolver
{
    public function __construct(
        private readonly RoleGrantQuery $query,
        private readonly PermissionCacheStore $versions,
        private readonly RoleCacheStore $cache,
        private readonly PermissionCacheKeys $keys,
        private readonly WorkspaceConfiguration $workspaces,
    ) {}

    public function resolve(Model $subject, AuthorizationContext $context): array
    {
        $scope = $context->workspaceScope($this->workspaces);
        if ($scope === null) {
            return [];
        }

        if (! (bool) config('kinship.cache.enabled', true)) {
            return $this->query->tokens($subject, $context);
        }

        $ttl = config('kinship.cache.ttl', 3600);
        if (! is_int($ttl) || $ttl < 1) {
            throw new RuntimeException('Kinship cache.ttl must be a positive integer number of seconds.');
        }

        $versionKeys = [
            $this->keys->scopeVersion($scope),
            $this->keys->subjectVersion($subject, $scope),
        ];

        try {
            $versions = $this->versions->versions($versionKeys);
            $cacheKey = $this->keys->roles($subject, $context, $versions);
            $roles = $this->cache->roles($cacheKey);
            if ($roles !== null) {
                return $roles;
            }
        } catch (Throwable) {
            return $this->query->tokens($subject, $context);
        }

        $roles = $this->query->tokens($subject, $context);

        try {
            $this->cache->putRoles($cacheKey, $roles, $ttl);
        } catch (Throwable) {
            // Role authorization remains database-backed when the cache is unavailable.
        }

        return $roles;
    }
}
