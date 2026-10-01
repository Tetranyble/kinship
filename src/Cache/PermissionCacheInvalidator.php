<?php

namespace Tetranyble\Kinship\Cache;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Contracts\PermissionCacheStore;
use Tetranyble\Kinship\Support\AuthorizationContext;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

final class PermissionCacheInvalidator
{
    private int $batchDepth = 0;

    /** @var array<string, true> */
    private array $pending = [];

    public function __construct(
        private readonly PermissionCacheStore $cache,
        private readonly PermissionCacheKeys $keys,
        private readonly WorkspaceConfiguration $workspaces,
    ) {}

    public function invalidateDefinitions(): void
    {
        $this->rotate($this->keys->definitionsVersion());
    }

    public function invalidateScope(string $scope): void
    {
        $this->rotate($this->keys->scopeVersion($scope));
    }

    public function invalidateSubject(Model $subject, AuthorizationContext $context): void
    {
        $scope = $context->workspaceScope($this->workspaces);
        if ($scope !== null) {
            $this->rotate($this->keys->subjectVersion($subject, $scope));
        }
    }

    public function invalidateSubjectScope(Model $subject, string $scope): void
    {
        $this->rotate($this->keys->subjectVersion($subject, $scope));
    }

    public function batch(Closure $callback): mixed
    {
        $this->batchDepth++;

        try {
            return $callback();
        } finally {
            $this->batchDepth--;

            if ($this->batchDepth === 0) {
                $pending = array_keys($this->pending);
                $this->pending = [];

                foreach ($pending as $key) {
                    $this->cache->rotate($key);
                }
            }
        }
    }

    private function rotate(string $key): void
    {
        if (! (bool) config('kinship.cache.enabled', true)) {
            return;
        }

        if ($this->batchDepth > 0) {
            $this->pending[$key] = true;

            return;
        }

        $this->cache->rotate($key);
    }
}
