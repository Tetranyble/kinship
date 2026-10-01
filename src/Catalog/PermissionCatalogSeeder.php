<?php

namespace Tetranyble\Kinship\Catalog;

use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Contracts\PermissionCatalog;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

final class PermissionCatalogSeeder
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly WorkspaceConfiguration $workspaces,
        private readonly PermissionCacheInvalidator $cache,
    ) {}

    public function seed(
        int|string|null $workspaceIdentifier = null,
        bool $workspaceScoped = false,
        bool $sync = false,
        bool $dryRun = false,
    ): CatalogSeedResult {
        if (! (bool) config('kinship.catalog.enabled', false)) {
            throw new RuntimeException('Kinship catalog seeding is disabled. Set kinship.catalog.enabled to true first.');
        }

        $scope = $this->workspaces->scopeValue($workspaceIdentifier, $workspaceScoped);
        if ($scope === null) {
            throw new RuntimeException('Kinship catalog seeding requires a workspace identifier in workspace mode.');
        }

        $permissions = $this->validatedPermissions($this->catalog->permissions());
        $roles = $this->validatedRoles($this->catalog->roles());
        $matrix = $this->matrix($permissions, $roles);
        $assignments = array_sum(array_map('count', $matrix));

        if ($dryRun) {
            return new CatalogSeedResult(count($permissions), count($roles), $assignments, 0, 0, 0, true);
        }

        $roleClass = KinshipModels::role();
        $permissionClass = KinshipModels::permission();
        $roleModel = new $roleClass;
        $permissionModel = new $permissionClass;

        if ($roleModel->getConnectionName() !== $permissionModel->getConnectionName()) {
            throw new RuntimeException('Kinship catalog role and permission models must use the same database connection.');
        }

        return $this->cache->batch(fn (): CatalogSeedResult => DB::connection($roleModel->getConnectionName())->transaction(function () use (
            $assignments,
            $matrix,
            $permissionClass,
            $permissions,
            $roleClass,
            $roles,
            $scope,
            $sync,
        ): CatalogSeedResult {
            $permissionModels = [];
            $restored = 0;

            foreach ($permissions as $definition) {
                $permission = $permissionClass::query()
                    ->withoutGlobalScope(SoftDeletingScope::class)
                    ->where('name', $definition->name)
                    ->first() ?? new $permissionClass;

                if (! $permission instanceof Permission) {
                    throw new RuntimeException('The configured Kinship permission model returned an invalid instance.');
                }

                $permission->fill([
                    'name' => $definition->name,
                    'label' => $definition->label,
                    'group' => $definition->group,
                ])->save();

                if ($permission->trashed()) {
                    $permission->restore();
                    $restored++;
                }

                $permissionModels[$definition->name] = $permission;
            }

            $attached = 0;
            $detached = 0;
            $workspaceColumn = $this->workspaces->roleForeignKey();

            foreach ($roles as $definition) {
                $role = $roleClass::query()
                    ->withoutGlobalScope(SoftDeletingScope::class)
                    ->where('name', $definition->name)
                    ->where($workspaceColumn, $scope)
                    ->first() ?? new $roleClass;

                if (! $role instanceof Role) {
                    throw new RuntimeException('The configured Kinship role model returned an invalid instance.');
                }

                $role->fill([
                    'name' => $definition->name,
                    'label' => $definition->label,
                    'description' => $definition->description,
                    'order' => $definition->order,
                    'is_system' => $definition->system,
                    $workspaceColumn => $scope,
                ])->save();

                if ($role->trashed()) {
                    $role->restore();
                    $restored++;
                }

                $ids = array_values(array_map(
                    fn (string $name): mixed => $permissionModels[$name]->getKey(),
                    $matrix[$definition->name],
                ));
                $changes = $sync
                    ? $role->permissions()->sync($ids)
                    : $role->permissions()->syncWithoutDetaching($ids);
                $attached += count($changes['attached']);
                $detached += count($changes['detached']);
                $this->cache->invalidateScope($scope);
            }

            return new CatalogSeedResult(
                count($permissions),
                count($roles),
                $assignments,
                $attached,
                $detached,
                $restored,
                false,
            );
        }));
    }

    /**
     * @param  list<PermissionDefinition>  $permissions
     * @param  list<RoleDefinition>  $roles
     * @return array<string, list<string>>
     */
    private function matrix(array $permissions, array $roles): array
    {
        $matrix = [];

        foreach ($roles as $role) {
            foreach ($role->permissions as $pattern) {
                $matched = array_filter(
                    $permissions,
                    fn (PermissionDefinition $permission): bool => $this->matches($permission->name, [$pattern]),
                );

                if ($matched === []) {
                    throw new RuntimeException(
                        "Kinship catalog role [{$role->name}] pattern [{$pattern}] matches no catalog permission.",
                    );
                }
            }

            $matrix[$role->name] = array_values(array_map(
                fn (PermissionDefinition $permission): string => $permission->name,
                array_filter(
                    $permissions,
                    fn (PermissionDefinition $permission): bool => $this->matches($permission->name, $role->permissions),
                ),
            ));
        }

        return $matrix;
    }

    /** @param list<string> $patterns */
    private function matches(string $permission, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '*' || Str::is($pattern, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $definitions
     * @return list<PermissionDefinition>
     */
    private function validatedPermissions(array $definitions): array
    {
        $names = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof PermissionDefinition) {
                throw new RuntimeException('Kinship catalog permissions must be PermissionDefinition instances.');
            }

            $this->nonEmpty($definition->name, 'permission name');
            $this->nonEmpty($definition->label, "permission [{$definition->name}] label");
            $this->nonEmpty($definition->group, "permission [{$definition->name}] group");

            if (isset($names[$definition->name])) {
                throw new RuntimeException("Duplicate Kinship catalog permission [{$definition->name}].");
            }

            $names[$definition->name] = true;
        }

        return array_values($definitions);
    }

    /**
     * @param  array<mixed>  $definitions
     * @return list<RoleDefinition>
     */
    private function validatedRoles(array $definitions): array
    {
        $names = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof RoleDefinition) {
                throw new RuntimeException('Kinship catalog roles must be RoleDefinition instances.');
            }

            $this->nonEmpty($definition->name, 'role name');
            $this->nonEmpty($definition->label, "role [{$definition->name}] label");

            foreach ($definition->permissions as $pattern) {
                $this->nonEmpty($pattern, "permission pattern for role [{$definition->name}]");
            }

            if (isset($names[$definition->name])) {
                throw new RuntimeException("Duplicate Kinship catalog role [{$definition->name}].");
            }

            $names[$definition->name] = true;
        }

        return array_values($definitions);
    }

    private function nonEmpty(string $value, string $description): void
    {
        if (trim($value) === '') {
            throw new RuntimeException("Kinship catalog {$description} must be a non-empty string.");
        }
    }
}
