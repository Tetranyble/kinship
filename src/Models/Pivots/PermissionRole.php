<?php

namespace Tetranyble\Kinship\Models\Pivots;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use RuntimeException;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

final class PermissionRole extends Pivot
{
    public $incrementing = false;

    protected static function booted(): void
    {
        self::creating(function (PermissionRole $pivot): void {
            $pivot->models();
        });

        $invalidate = function (PermissionRole $pivot): void {
            $roleKey = (string) config('kinship.columns.role_foreign_key', 'role_id');
            $roleId = $pivot->getAttribute($roleKey);
            if (! is_int($roleId) && ! is_string($roleId)) {
                return;
            }

            $roleClass = KinshipModels::role();
            $scopeKey = app(WorkspaceConfiguration::class)->roleForeignKey();
            $scope = $roleClass::query()->withTrashed()->whereKey($roleId)->value($scopeKey);
            if (is_int($scope) || is_string($scope)) {
                app(PermissionCacheInvalidator::class)->invalidateScope((string) $scope);
            }
        };

        self::saved($invalidate);
        self::deleted($invalidate);
    }

    /** @return array{0: Model, 1: Model} */
    private function models(): array
    {
        $roleKey = (string) config('kinship.columns.role_foreign_key', 'role_id');
        $permissionKey = (string) config('kinship.columns.permission_foreign_key', 'permission_id');
        $roleClass = KinshipModels::role();
        $permissionClass = KinshipModels::permission();
        $role = $roleClass::query()->find($this->getAttribute($roleKey));
        $permission = $permissionClass::query()->find($this->getAttribute($permissionKey));

        if (! $role instanceof Model || ! $permission instanceof Model) {
            throw new RuntimeException('Kinship role permission assignment references missing or deleted models.');
        }

        return [$role, $permission];
    }
}
