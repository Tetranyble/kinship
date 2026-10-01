<?php

namespace Tetranyble\Kinship\Permissions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
        $groupClass = KinshipModels::group();
        $permission = new $permissionClass;
        $role = new $roleClass;
        $group = new $groupClass;

        $connections = array_unique([
            $permission->getConnectionName(),
            $role->getConnectionName(),
            $group->getConnectionName(),
        ]);

        if (count($connections) !== 1) {
            throw new RuntimeException('Kinship role, permission, and group models must use the same database connection.');
        }

        $connection = DB::connection($permission->getConnectionName());
        $permissions = $permission->getTable();
        $roles = $role->getTable();
        $groups = $group->getTable();
        $permissionUser = (string) config('kinship.tables.permission_user', 'permission_user');
        $roleUser = (string) config('kinship.tables.role_user', 'role_user');
        $permissionRole = (string) config('kinship.tables.permission_role', 'permission_role');
        $groupUser = (string) config('kinship.tables.group_user', 'group_user');
        $groupRole = (string) config('kinship.tables.group_role', 'group_role');
        $groupPermission = (string) config('kinship.tables.group_permission', 'group_permission');
        $permissionKey = (string) config('kinship.columns.permission_foreign_key', 'permission_id');
        $roleKey = (string) config('kinship.columns.role_foreign_key', 'role_id');
        $groupKey = (string) config('kinship.columns.group_foreign_key', 'group_id');
        $userKey = (string) config('kinship.columns.user_foreign_key', 'user_id');
        $roleScopeKey = $this->workspaces->roleForeignKey();
        $groupScopeKey = $this->workspaces->groupForeignKey();
        $permissionPk = $permission->getKeyName();
        $rolePk = $role->getKeyName();
        $groupPk = $group->getKeyName();

        $direct = $connection->table("{$permissionUser} as kinship_pu")
            ->join("{$permissions} as kinship_dp", "kinship_dp.{$permissionPk}", '=', "kinship_pu.{$permissionKey}")
            ->where("kinship_pu.{$userKey}", $subjectKey)
            ->where("kinship_pu.{$roleScopeKey}", $scope)
            ->whereNull('kinship_dp.deleted_at')
            ->select('kinship_dp.name as name');

        $viaRoles = $connection->table("{$roleUser} as kinship_ru")
            ->join("{$roles} as kinship_r", "kinship_r.{$rolePk}", '=', "kinship_ru.{$roleKey}")
            ->join("{$permissionRole} as kinship_pr", "kinship_pr.{$roleKey}", '=', "kinship_r.{$rolePk}")
            ->join("{$permissions} as kinship_rp", "kinship_rp.{$permissionPk}", '=', "kinship_pr.{$permissionKey}")
            ->where("kinship_ru.{$userKey}", $subjectKey)
            ->where("kinship_r.{$roleScopeKey}", $scope)
            ->whereNull('kinship_r.deleted_at')
            ->whereNull('kinship_rp.deleted_at')
            ->select('kinship_rp.name as name');

        $viaGroupPermissions = $connection->table("{$groupUser} as kinship_gu")
            ->join("{$groups} as kinship_g", "kinship_g.{$groupPk}", '=', "kinship_gu.{$groupKey}")
            ->join("{$groupPermission} as kinship_gp", "kinship_gp.{$groupKey}", '=', "kinship_g.{$groupPk}")
            ->join("{$permissions} as kinship_gp_permission", "kinship_gp_permission.{$permissionPk}", '=', "kinship_gp.{$permissionKey}")
            ->where("kinship_gu.{$userKey}", $subjectKey)
            ->where("kinship_g.{$groupScopeKey}", $scope)
            ->whereNull('kinship_gp_permission.deleted_at')
            ->select('kinship_gp_permission.name as name');

        $viaGroupRoles = $connection->table("{$groupUser} as kinship_gu2")
            ->join("{$groups} as kinship_g2", "kinship_g2.{$groupPk}", '=', "kinship_gu2.{$groupKey}")
            ->join("{$groupRole} as kinship_gr", "kinship_gr.{$groupKey}", '=', "kinship_g2.{$groupPk}")
            ->join("{$roles} as kinship_gr_role", "kinship_gr_role.{$rolePk}", '=', "kinship_gr.{$roleKey}")
            ->join("{$permissionRole} as kinship_gr_pr", "kinship_gr_pr.{$roleKey}", '=', "kinship_gr_role.{$rolePk}")
            ->join("{$permissions} as kinship_gr_permission", "kinship_gr_permission.{$permissionPk}", '=', "kinship_gr_pr.{$permissionKey}")
            ->where("kinship_gu2.{$userKey}", $subjectKey)
            ->where("kinship_g2.{$groupScopeKey}", $scope)
            ->where("kinship_gr_role.{$roleScopeKey}", $scope)
            ->whereNull('kinship_gr_role.deleted_at')
            ->whereNull('kinship_gr_permission.deleted_at')
            ->select('kinship_gr_permission.name as name');

        if (in_array(SoftDeletes::class, class_uses_recursive($group), true)) {
            $viaGroupPermissions->whereNull('kinship_g.deleted_at');
            $viaGroupRoles->whereNull('kinship_g2.deleted_at');
        }

        return $direct
            ->union($viaRoles)
            ->union($viaGroupPermissions)
            ->union($viaGroupRoles);
    }
}
