<?php

namespace Tetranyble\Kinship\Permissions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tetranyble\Kinship\Contracts\PermissionGrantSource;
use Tetranyble\Kinship\Support\AuthorizationContext;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

final class DatabasePermissionGrantSource implements PermissionGrantSource
{
    public function __construct(private readonly WorkspaceConfiguration $workspaces) {}

    public function query(Model $subject, AuthorizationContext $context): ?Builder
    {
        $scope = $context->workspaceScope($this->workspaces);
        if ($scope === null) {
            return null;
        }

        $subjectKey = $subject->getKey();
        if (! is_int($subjectKey) && ! is_string($subjectKey)) {
            throw new RuntimeException('Kinship cannot resolve permissions for an unpersisted subject.');
        }

        $permissionClass = KinshipModels::permission();
        $roleClass = KinshipModels::role();
        $permission = new $permissionClass;
        $role = new $roleClass;

        if ($permission->getConnectionName() !== $role->getConnectionName()) {
            throw new RuntimeException('Kinship role and permission models must use the same database connection.');
        }

        $connection = DB::connection($permission->getConnectionName());
        $permissions = $permission->getTable();
        $roles = $role->getTable();
        $permissionUser = (string) config('kinship.tables.permission_user', 'permission_user');
        $roleUser = (string) config('kinship.tables.role_user', 'role_user');
        $permissionRole = (string) config('kinship.tables.permission_role', 'permission_role');
        $permissionKey = (string) config('kinship.columns.permission_foreign_key', 'permission_id');
        $roleKey = (string) config('kinship.columns.role_foreign_key', 'role_id');
        $userKey = (string) config('kinship.columns.user_foreign_key', 'user_id');

        $direct = $connection->table("{$permissionUser} as kinship_pu")
            ->join("{$permissions} as kinship_dp", "kinship_dp.{$permission->getKeyName()}", '=', "kinship_pu.{$permissionKey}")
            ->where("kinship_pu.{$userKey}", $subjectKey)
            ->where('kinship_dp.guard_name', $context->guard)
            ->whereNull('kinship_dp.deleted_at')
            ->select('kinship_dp.name as name');

        $viaRoles = $connection->table("{$roleUser} as kinship_ru")
            ->join("{$roles} as kinship_r", "kinship_r.{$role->getKeyName()}", '=', "kinship_ru.{$roleKey}")
            ->join("{$permissionRole} as kinship_pr", "kinship_pr.{$roleKey}", '=', "kinship_r.{$role->getKeyName()}")
            ->join("{$permissions} as kinship_rp", "kinship_rp.{$permission->getKeyName()}", '=', "kinship_pr.{$permissionKey}")
            ->where("kinship_ru.{$userKey}", $subjectKey)
            ->where('kinship_r.guard_name', $context->guard)
            ->where('kinship_rp.guard_name', $context->guard)
            ->where("kinship_r.{$this->workspaces->roleForeignKey()}", $scope)
            ->whereNull('kinship_r.deleted_at')
            ->whereNull('kinship_rp.deleted_at')
            ->select('kinship_rp.name as name');

        return $direct->union($viaRoles);
    }
}
