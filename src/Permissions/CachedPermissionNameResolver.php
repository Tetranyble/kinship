<?php

namespace Tetranyble\Kinship\Permissions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Tetranyble\Kinship\Cache\PermissionCacheKeys;
use Tetranyble\Kinship\Contracts\PermissionCacheStore;
use Tetranyble\Kinship\Contracts\PermissionNameResolver;
use Tetranyble\Kinship\Support\AuthorizationContext;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;
use Throwable;

final class CachedPermissionNameResolver implements PermissionNameResolver
{
    public function __construct(
        private readonly PermissionGrantQuery $query,
        private readonly PermissionCacheStore $cache,
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
            return $this->query->names($subject, $context);
        }

        $ttl = config('kinship.cache.ttl', 3600);
        if (! is_int($ttl) || $ttl < 1) {
            throw new RuntimeException('Kinship cache.ttl must be a positive integer number of seconds.');
        }

        $versionKeys = [
            $this->keys->guardVersion($context->guard),
            $this->keys->scopeVersion($context->guard, $scope),
            $this->keys->subjectVersion($subject, $context->guard),
        ];

        try {
            $versions = $this->cache->versions($versionKeys);
            $cacheKey = $this->keys->permissions($subject, $context, $versions);
            $permissions = $this->cache->permissions($cacheKey);

            if ($permissions !== null) {
                return $permissions;
            }
        } catch (Throwable) {
            return $this->query->names($subject, $context);
        }

        $permissions = $this->query->names($subject, $context);

        try {
            $this->cache->putPermissions($cacheKey, $permissions, $ttl);
        } catch (Throwable) {
            // Cache availability must not make the authorization DB unavailable.
        }

        return $permissions;
    }
}
