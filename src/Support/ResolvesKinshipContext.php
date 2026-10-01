<?php

namespace Tetranyble\Kinship\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Contracts\AuthorizationContextResolver;
use Tetranyble\Kinship\Contracts\Group as GroupContract;

trait ResolvesKinshipContext
{
    protected function kinshipAuthorizationContext(): AuthorizationContext
    {
        return app(AuthorizationContextResolver::class)->resolve($this);
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    protected function scopeKinshipRoleQuery(Builder $query): Builder
    {
        $configuration = app(WorkspaceConfiguration::class);
        $scope = $this->kinshipAuthorizationContext()->workspaceScope($configuration);

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
        $expected = $this->kinshipAuthorizationContext()->workspaceScope($configuration);
        $actual = $role instanceof GroupContract
            ? $role->getKinshipWorkspaceIdentifier()
            : $role->getAttribute($configuration->roleForeignKey());

        return $expected !== null && is_scalar($actual) && (string) $actual === $expected;
    }
}
