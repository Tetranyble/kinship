<?php

namespace Tetranyble\Kinship\Models\Pivots;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Events\GroupRoleAssigned;
use Tetranyble\Kinship\Events\GroupRoleRevoked;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceIsolation;

final class GroupRole extends Pivot
{
    public $incrementing = false;

    protected static function booted(): void
    {
        self::creating(function (GroupRole $pivot): void {
            [$group, $role] = $pivot->models();
            app(WorkspaceIsolation::class)->assertModelsShareScope($group, $role, 'group role assignment');
        });

        self::created(function (GroupRole $pivot): void {
            [$group, $role] = $pivot->models(true);
            $scope = app(WorkspaceIsolation::class)->scopeOf($group);
            if ($scope !== null) {
                app(PermissionCacheInvalidator::class)->invalidateScope($scope);
                event(new GroupRoleAssigned($group->getKey(), $role->getKey(), $scope));
            }
        });

        self::deleted(function (GroupRole $pivot): void {
            [$group, $role] = $pivot->models(true);
            $scope = app(WorkspaceIsolation::class)->scopeOf($group);
            if ($scope !== null) {
                app(PermissionCacheInvalidator::class)->invalidateScope($scope);
                event(new GroupRoleRevoked($group->getKey(), $role->getKey(), $scope));
            }
        });
    }

    /** @return array{0: Model, 1: Model} */
    private function models(bool $withTrashed = false): array
    {
        $groupKey = (string) config('kinship.columns.group_foreign_key', 'group_id');
        $roleKey = (string) config('kinship.columns.role_foreign_key', 'role_id');
        $groupClass = KinshipModels::group();
        $roleClass = KinshipModels::role();
        $groupQuery = $groupClass::query();
        $roleQuery = $roleClass::query();

        if ($withTrashed) {
            if (in_array(SoftDeletes::class, class_uses_recursive($groupQuery->getModel()), true)) {
                $groupQuery->withTrashed();
            }
            $roleQuery->withTrashed();
        }

        $group = $groupQuery->find($this->getAttribute($groupKey));
        $role = $roleQuery->find($this->getAttribute($roleKey));

        if (! $group instanceof Model || ! $role instanceof Model) {
            throw new RuntimeException('Kinship group role assignment references missing models.');
        }

        return [$group, $role];
    }
}
