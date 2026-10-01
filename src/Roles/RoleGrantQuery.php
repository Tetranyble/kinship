<?php

namespace Tetranyble\Kinship\Roles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tetranyble\Kinship\Support\AuthorizationContext;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

final class RoleGrantQuery
{
    public function __construct(private readonly WorkspaceConfiguration $workspaces) {}

    /** @return list<string> */
    public function tokens(Model $subject, AuthorizationContext $context): array
    {
        $scope = $context->workspaceScope($this->workspaces);
        if ($scope === null) {
            return [];
        }

        $subjectKey = $subject->getKey();
        if (! is_int($subjectKey) && ! is_string($subjectKey)) {
            throw new RuntimeException('Kinship cannot resolve roles for an unpersisted subject.');
        }

        $roleClass = KinshipModels::role();
        $groupClass = KinshipModels::group();
        $role = new $roleClass;
        $group = new $groupClass;

        if ($role->getConnectionName() !== $group->getConnectionName()) {
            throw new RuntimeException('Kinship role and group models must use the same database connection.');
        }

        $connection = DB::connection($role->getConnectionName());
        $roles = $role->getTable();
        $groups = $group->getTable();
        $roleUser = (string) config('kinship.tables.role_user', 'role_user');
        $groupUser = (string) config('kinship.tables.group_user', 'group_user');
        $groupRole = (string) config('kinship.tables.group_role', 'group_role');
        $roleKey = (string) config('kinship.columns.role_foreign_key', 'role_id');
        $groupKey = (string) config('kinship.columns.group_foreign_key', 'group_id');
        $userKey = (string) config('kinship.columns.user_foreign_key', 'user_id');
        $roleScopeKey = $this->workspaces->roleForeignKey();
        $groupScopeKey = $this->workspaces->groupForeignKey();
        $rolePk = $role->getKeyName();

        $direct = $connection->table("{$roleUser} as kinship_ru")
            ->join("{$roles} as kinship_r", "kinship_r.{$rolePk}", '=', "kinship_ru.{$roleKey}")
            ->where("kinship_ru.{$userKey}", $subjectKey)
            ->where("kinship_r.{$roleScopeKey}", $scope)
            ->whereNull('kinship_r.deleted_at')
            ->selectRaw("kinship_r.{$rolePk} as kinship_role_id, kinship_r.name as kinship_role_name, kinship_r.label as kinship_role_label");

        $viaGroups = $connection->table("{$groupUser} as kinship_gu")
            ->join("{$groups} as kinship_g", "kinship_g.{$group->getKeyName()}", '=', "kinship_gu.{$groupKey}")
            ->join("{$groupRole} as kinship_gr", "kinship_gr.{$groupKey}", '=', "kinship_g.{$group->getKeyName()}")
            ->join("{$roles} as kinship_r2", "kinship_r2.{$rolePk}", '=', "kinship_gr.{$roleKey}")
            ->where("kinship_gu.{$userKey}", $subjectKey)
            ->where("kinship_g.{$groupScopeKey}", $scope)
            ->where("kinship_r2.{$roleScopeKey}", $scope)
            ->whereNull('kinship_r2.deleted_at')
            ->selectRaw("kinship_r2.{$rolePk} as kinship_role_id, kinship_r2.name as kinship_role_name, kinship_r2.label as kinship_role_label");

        if (in_array(SoftDeletes::class, class_uses_recursive($group), true)) {
            $viaGroups->whereNull('kinship_g.deleted_at');
        }

        $rows = $direct->union($viaGroups)->get();
        $tokens = [];

        foreach ($rows as $row) {
            $id = $row->kinship_role_id ?? null;
            $name = $row->kinship_role_name ?? null;
            $label = $row->kinship_role_label ?? null;

            if (is_int($id) || is_string($id)) {
                $tokens[] = 'id:'.(string) $id;
            }
            if (is_string($name) && $name !== '') {
                $tokens[] = 'name:'.$name;
            }
            if (is_string($label) && $label !== '') {
                $tokens[] = 'label:'.$label;
            }
        }

        return collect($tokens)->unique()->values()->all();
    }
}
