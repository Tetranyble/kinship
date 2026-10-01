<?php

namespace Tetranyble\Kinship\Models\Pivots;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Events\GroupPermissionGranted;
use Tetranyble\Kinship\Events\GroupPermissionRevoked;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceIsolation;

final class GroupPermission extends Pivot
{
    public $incrementing = false;

    protected static function booted(): void
    {
        self::creating(function (GroupPermission $pivot): void {
            $pivot->models();
        });

        self::created(function (GroupPermission $pivot): void {
            [$group, $permission] = $pivot->models(true);
            $scope = app(WorkspaceIsolation::class)->scopeOf($group);
            if ($scope !== null) {
                app(PermissionCacheInvalidator::class)->invalidateScope($scope);
                event(new GroupPermissionGranted($group->getKey(), $permission->getKey(), $scope));
            }
        });

        self::deleted(function (GroupPermission $pivot): void {
            [$group, $permission] = $pivot->models(true);
            $scope = app(WorkspaceIsolation::class)->scopeOf($group);
            if ($scope !== null) {
                app(PermissionCacheInvalidator::class)->invalidateScope($scope);
                event(new GroupPermissionRevoked($group->getKey(), $permission->getKey(), $scope));
            }
        });
    }

    /** @return array{0: Model, 1: Model} */
    private function models(bool $withTrashed = false): array
    {
        $groupKey = (string) config('kinship.columns.group_foreign_key', 'group_id');
        $permissionKey = (string) config('kinship.columns.permission_foreign_key', 'permission_id');
        $groupClass = KinshipModels::group();
        $permissionClass = KinshipModels::permission();
        $groupQuery = $groupClass::query();
        $permissionQuery = $permissionClass::query();

        if ($withTrashed) {
            if (in_array(SoftDeletes::class, class_uses_recursive($groupQuery->getModel()), true)) {
                $groupQuery->withTrashed();
            }
            $permissionQuery->withTrashed();
        }

        $group = $groupQuery->find($this->getAttribute($groupKey));
        $permission = $permissionQuery->find($this->getAttribute($permissionKey));

        if (! $group instanceof Model || ! $permission instanceof Model) {
            throw new RuntimeException('Kinship group permission assignment references missing models.');
        }

        return [$group, $permission];
    }
}
