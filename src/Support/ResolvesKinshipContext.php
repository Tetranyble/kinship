<?php

namespace Tetranyble\Kinship\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Contracts\AuthorizationContextResolver;
use Tetranyble\Kinship\Contracts\GuardResolver;

trait ResolvesKinshipContext
{
    protected function kinshipGuardName(): string
    {
        return app(GuardResolver::class)->resolve($this);
    }

    protected function kinshipAuthorizationContext(): AuthorizationContext
    {
        return app(AuthorizationContextResolver::class)->resolve($this, $this->kinshipGuardName());
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    protected function scopeKinshipRoleQuery(Builder $query): Builder
    {
        $configuration = app(WorkspaceConfiguration::class);
        $context = $this->kinshipAuthorizationContext();
        $scope = $context->workspaceScope($configuration);
        $query->where('guard_name', $context->guard);

        if ($scope === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where($configuration->roleForeignKey(), $scope);
        }

        return $query;
    }

    protected function sameKinshipWorkspace(Model $role): bool
    {
        $configuration = app(WorkspaceConfiguration::class);
        $context = $this->kinshipAuthorizationContext();
        $expected = $context->workspaceScope($configuration);
        $actual = $role->getAttribute($configuration->roleForeignKey());

        return $expected !== null && is_scalar($actual) && (string) $actual === $expected;
    }
}
