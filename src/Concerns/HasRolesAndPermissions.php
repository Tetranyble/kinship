<?php

namespace Tetranyble\Kinship\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use RuntimeException;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Contracts\EffectiveRoleResolver;
use Tetranyble\Kinship\Contracts\Group as GroupContract;
use Tetranyble\Kinship\Contracts\PermissionNameResolver;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Pivots\GroupUser;
use Tetranyble\Kinship\Models\Pivots\PermissionUser;
use Tetranyble\Kinship\Models\Pivots\RoleUser;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Support\AuthorizationContext;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\ResolvesKinshipContext;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

trait HasRolesAndPermissions
{
    use ResolvesKinshipContext;

    /** @return BelongsToMany<Role, $this, RoleUser> */
    public function roles(): BelongsToMany
    {
        $relation = $this->allRoles();
        $this->scopeKinshipRoleQuery($relation->getQuery());

        return $relation;
    }

    /**
     * Unscoped persistence relationship. Authorization checks must use roles().
     */
    /** @return BelongsToMany<Role, $this, RoleUser> */
    public function allRoles(): BelongsToMany
    {
        $roleClass = KinshipModels::role();

        return $this->belongsToMany(
            $roleClass,
            (string) config('kinship.tables.role_user', 'role_user'),
            (string) config('kinship.columns.user_foreign_key', 'user_id'),
            (string) config('kinship.columns.role_foreign_key', 'role_id'),
        )->using(RoleUser::class)->withTimestamps();
    }

    /** @return BelongsToMany<Model&GroupContract, $this, GroupUser> */
    public function groups(): BelongsToMany
    {
        $relation = $this->allGroups();
        $configuration = app(WorkspaceConfiguration::class);
        $scope = $this->kinshipAuthorizationContext()->workspaceScope($configuration);

        if ($scope === null) {
            $relation->getQuery()->whereRaw('1 = 0');
        } else {
            $relation->getQuery()->where(
                $relation->getRelated()->qualifyColumn($configuration->groupForeignKey()),
                $scope,
            );
        }

        return $relation;
    }

    /**
     * Unscoped persistence relationship. Authorization checks must use groups().
     * A single identity may be provisioned into groups in several workspaces.
     *
     * @return BelongsToMany<Model&GroupContract, $this, GroupUser>
     */
    public function allGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            KinshipModels::group(),
            (string) config('kinship.tables.group_user', 'group_user'),
            (string) config('kinship.columns.user_foreign_key', 'user_id'),
            (string) config('kinship.columns.group_foreign_key', 'group_id'),
        )->using(GroupUser::class)->withTimestamps();
    }

    /** @return BelongsToMany<Permission, $this, PermissionUser> */
    public function userPermissions(): BelongsToMany
    {
        $permissionClass = KinshipModels::permission();
        $configuration = app(WorkspaceConfiguration::class);
        $scopeKey = $configuration->roleForeignKey();
        $scope = $this->kinshipWorkspaceScope();

        return $this->belongsToMany(
            $permissionClass,
            (string) config('kinship.tables.permission_user', 'permission_user'),
            (string) config('kinship.columns.user_foreign_key', 'user_id'),
            (string) config('kinship.columns.permission_foreign_key', 'permission_id'),
        )->using(PermissionUser::class)
            ->withPivot($scopeKey)
            ->withPivotValue($scopeKey, $scope)
            ->withTimestamps();
    }

    public function primaryRole(): ?Role
    {
        $role = $this->roles()->getQuery()
            ->orderBy('order')
            ->first();

        return $role instanceof Role ? $role : null;
    }

    public function getRoleAttribute(): ?Role
    {
        return $this->primaryRole();
    }

    /** @return Collection<int, Permission> */
    public function allPermissions(): Collection
    {
        $names = $this->allPermissionNames();
        if ($names->isEmpty()) {
            return collect();
        }

        $permissionClass = KinshipModels::permission();

        return $permissionClass::query()
            ->whereIn('name', $names->all())
            ->get()
            ->filter(fn (mixed $permission): bool => $permission instanceof Permission)
            ->values();
    }

    /** @return Collection<int, string> */
    public function allPermissionNames(): Collection
    {
        $context = $this->kinshipAuthorizationContext();
        $acting = $this->getActingRole();
        $names = $acting !== null && $this->kinshipActingRoleMode() === 'replace'
            ? []
            : $this->resolveKinshipPermissionNames($context);

        if ($acting !== null) {
            foreach ($acting->permissions()->getQuery()->pluck('name') as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        return collect($names)->unique()->values();
    }

    /** @return Collection<int, string> */
    public function permissions(): Collection
    {
        return $this->allPermissionNames();
    }

    /** @return Collection<int, string> */
    public function allRoleNames(): Collection
    {
        return collect(array_keys($this->kinshipEffectiveRoleSet()))
            ->filter(fn (string $token): bool => str_starts_with($token, 'name:'))
            ->map(fn (string $token): string => substr($token, 5))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values();
    }

    public function hasRoles(mixed $roles): bool
    {
        return $this->hasAnyRole($roles);
    }

    public function hasRole(mixed $role): bool
    {
        return $this->hasAnyRole($role);
    }

    public function hasAnyRole(mixed ...$roles): bool
    {
        $needles = collect($roles)->flatten()->filter(fn (mixed $role): bool => $this->validNeedle($role));

        if ($needles->isEmpty()) {
            return false;
        }

        return $needles->contains(fn (mixed $needle): bool => $this->roleCollectionContains($needle));
    }

    public function hasAllRoles(mixed ...$roles): bool
    {
        $needles = collect($roles)->flatten()->filter(fn (mixed $role): bool => $this->validNeedle($role));

        return $needles->isNotEmpty()
            && $needles->every(fn (mixed $needle): bool => $this->roleCollectionContains($needle));
    }

    public function assignRoles(mixed ...$roles): static
    {
        $ids = $this->resolveRoleIds($roles);

        if ($ids !== []) {
            app(PermissionCacheInvalidator::class)->batch(
                fn () => $this->allRoles()->syncWithoutDetaching($ids),
            );
            $this->forgetKinshipCache();
        }

        return $this;
    }

    public function syncRoles(mixed ...$roles): static
    {
        $ids = $this->resolveRoleIds($roles);
        $relation = $this->roles();
        $related = $relation->getRelated();
        $currentIds = $relation->getQuery()
            ->pluck($related->qualifyColumn($related->getKeyName()))
            ->all();
        app(PermissionCacheInvalidator::class)->batch(function () use ($ids, $currentIds): void {
            $this->allRoles()->syncWithoutDetaching($ids);

            $detach = array_values(array_diff($currentIds, $ids));
            if ($detach !== []) {
                $this->allRoles()->detach($detach);
            }
        });
        $this->forgetKinshipCache();

        return $this;
    }

    public function removeRoles(mixed ...$roles): static
    {
        $ids = $this->resolveRoleIds($roles);

        if ($ids !== []) {
            app(PermissionCacheInvalidator::class)->batch(fn () => $this->allRoles()->detach($ids));
            $this->forgetKinshipCache();
        }

        return $this;
    }

    public function hasPermissions(mixed ...$permissions): bool
    {
        return $this->hasAnyPermission(...$permissions);
    }

    public function hasPermission(mixed $permission): bool
    {
        return $this->hasAnyPermission($permission);
    }

    public function hasAnyPermission(mixed ...$permissions): bool
    {
        $needles = $this->permissionNames($permissions);
        $granted = $this->kinshipPermissionSet();

        return $needles->isNotEmpty() && $needles->contains(fn (string $permission): bool => isset($granted[$permission]));
    }

    public function hasAllPermissions(mixed ...$permissions): bool
    {
        $needles = $this->permissionNames($permissions);
        $granted = $this->kinshipPermissionSet();

        return $needles->isNotEmpty() && $needles->every(fn (string $permission): bool => isset($granted[$permission]));
    }

    public function assignPermissions(mixed ...$permissions): static
    {
        $ids = $this->resolvePermissionIds($permissions);

        if ($ids !== []) {
            app(PermissionCacheInvalidator::class)->batch(
                fn () => $this->userPermissions()->syncWithoutDetaching($this->permissionPivotPayload($ids)),
            );
            $this->forgetKinshipCache();
        }

        return $this;
    }

    public function syncPermissions(mixed ...$permissions): static
    {
        $ids = $this->resolvePermissionIds($permissions);
        app(PermissionCacheInvalidator::class)->batch(
            fn () => $this->userPermissions()->sync($this->permissionPivotPayload($ids)),
        );
        $this->forgetKinshipCache();

        return $this;
    }

    public function removePermissions(mixed ...$permissions): static
    {
        $ids = $this->resolvePermissionIds($permissions);

        if ($ids !== []) {
            app(PermissionCacheInvalidator::class)->batch(fn () => $this->userPermissions()->detach($ids));
            $this->forgetKinshipCache();
        }

        return $this;
    }

    public function hasGroup(mixed $group): bool
    {
        return $this->hasAnyGroup($group);
    }

    public function hasAnyGroup(mixed ...$groups): bool
    {
        $needles = collect($groups)->flatten()->filter(fn (mixed $group): bool => $this->validGroupNeedle($group));

        if ($needles->isEmpty()) {
            return false;
        }

        $relation = $this->groups();
        $groupModel = $relation->getRelated();
        $keyName = $groupModel->getKeyName();

        $groupClass = $groupModel::class;
        $lookupColumns = $this->kinshipGroupLookupColumns();

        return $needles->contains(function (mixed $needle) use ($relation, $keyName, $groupClass, $lookupColumns): bool {
            $query = clone $relation->getQuery();

            if ($needle instanceof $groupClass) {
                return $this->sameKinshipWorkspace($needle)
                    && $needle->getKey() !== null
                    && $query->whereKey($needle->getKey())->exists();
            }

            if (is_int($needle)) {
                return $query->where($keyName, $needle)->exists();
            }

            return $query->where(function (Builder $candidate) use ($keyName, $needle, $lookupColumns): void {
                $candidate->where($keyName, $needle);
                foreach ($lookupColumns as $column) {
                    if ($column !== $keyName) {
                        $candidate->orWhere($column, $needle);
                    }
                }
            })->exists();
        });
    }

    public function hasAllGroups(mixed ...$groups): bool
    {
        $needles = collect($groups)->flatten()->filter(fn (mixed $group): bool => $this->validGroupNeedle($group));

        return $needles->isNotEmpty()
            && $needles->every(fn (mixed $needle): bool => $this->hasGroup($needle));
    }

    public function assignGroups(mixed ...$groups): static
    {
        $ids = $this->resolveGroupIds($groups);

        if ($ids !== []) {
            app(PermissionCacheInvalidator::class)->batch(
                fn () => $this->allGroups()->syncWithoutDetaching($ids),
            );
            $this->forgetKinshipCache();
        }

        return $this;
    }

    public function syncGroups(mixed ...$groups): static
    {
        $ids = $this->resolveGroupIds($groups);
        $relation = $this->groups();
        $related = $relation->getRelated();
        $currentIds = $relation->getQuery()
            ->pluck($related->qualifyColumn($related->getKeyName()))
            ->all();

        app(PermissionCacheInvalidator::class)->batch(function () use ($ids, $currentIds): void {
            $this->allGroups()->syncWithoutDetaching($ids);
            $detach = array_values(array_diff($currentIds, $ids));
            if ($detach !== []) {
                $this->allGroups()->detach($detach);
            }
        });
        $this->forgetKinshipCache();

        return $this;
    }

    public function removeGroups(mixed ...$groups): static
    {
        $ids = $this->resolveGroupIds($groups);

        if ($ids !== []) {
            app(PermissionCacheInvalidator::class)->batch(fn () => $this->allGroups()->detach($ids));
            $this->forgetKinshipCache();
        }

        return $this;
    }

    public function getActingRole(): ?Role
    {
        if (! (bool) config('kinship.acting_roles.enabled', true) || ! app()->bound('session')) {
            return null;
        }

        $roleId = session($this->kinshipActingRoleSessionKey());
        if ($roleId === null) {
            return null;
        }

        $roleClass = KinshipModels::role();
        $role = $this->scopeKinshipRoleQuery($roleClass::query())->find($roleId);

        return $role instanceof Role ? $role : null;
    }

    public function isActingAs(): bool
    {
        return $this->getActingRole() !== null;
    }

    public function assumedRole(): ?Role
    {
        return $this->getActingRole();
    }

    public function isAssumingRole(): bool
    {
        return $this->isActingAs();
    }

    public function assumeRole(mixed $role): bool
    {
        return $this->actAs($role);
    }

    public function actAs(mixed $role): bool
    {
        if (! (bool) config('kinship.acting_roles.enabled', true)) {
            return false;
        }

        $roleId = $this->resolveRoleIds([$role])[0] ?? null;
        if ($roleId === null) {
            return false;
        }

        if (! (bool) config('kinship.acting_roles.allow_unassigned', false)) {
            $assigned = array_fill_keys(
                app(EffectiveRoleResolver::class)->resolve($this, $this->kinshipAuthorizationContext()),
                true,
            );

            if (! isset($assigned['id:'.(string) $roleId])) {
                return false;
            }
        }

        if (app()->bound('session')) {
            session([$this->kinshipActingRoleSessionKey() => $roleId]);
        }

        $roleClass = KinshipModels::role();
        $actingRole = $this->scopeKinshipRoleQuery($roleClass::query())
            ->whereKey($roleId)
            ->first();

        if (! $actingRole instanceof Role) {
            if (app()->bound('session')) {
                session()->forget($this->kinshipActingRoleSessionKey());
            }

            return false;
        }

        $this->forgetKinshipCache();

        return true;
    }

    public function stopActingAs(): void
    {
        if (app()->bound('session')) {
            session()->forget($this->kinshipActingRoleSessionKey());
        }

        $this->forgetKinshipCache();
    }

    public function stopAssumingRole(): void
    {
        $this->stopActingAs();
    }

    public function clearAssumedRoles(): void
    {
        if (app()->bound('session')) {
            $prefix = $this->kinshipActingRoleSessionPrefix();
            $keys = array_values(array_filter(
                array_keys(session()->all()),
                fn (mixed $key): bool => is_string($key) && str_starts_with($key, $prefix),
            ));

            if ($keys !== []) {
                session()->forget($keys);
            }
        }

        $this->forgetKinshipCache();
    }

    public function forgetKinshipAuthorizationCache(): static
    {
        $this->invalidateKinshipSharedCache();
        $this->forgetKinshipCache();

        return $this;
    }

    protected function forgetKinshipCache(): void
    {
        $this->unsetRelation('roles');
        $this->unsetRelation('groups');
        $this->unsetRelation('userPermissions');

    }

    /**
     * @param  array<int, mixed>  $roles
     * @return list<int|string>
     */
    protected function resolveRoleIds(array $roles): array
    {
        $roleClass = KinshipModels::role();
        $values = collect($roles)->flatten();
        $query = $this->scopeKinshipRoleQuery($roleClass::query());
        $keyName = (new $roleClass)->getKeyName();
        $resolved = [];

        foreach ($values as $value) {
            if ($value instanceof $roleClass) {
                if ($this->sameKinshipWorkspace($value) && $value->getKey() !== null) {
                    $resolved[] = $value->getKey();
                }

                continue;
            }

            if (! is_int($value) && ! (is_string($value) && trim($value) !== '')) {
                continue;
            }

            $matches = (clone $query)
                ->where(function (Builder $candidate) use ($keyName, $value): void {
                    $candidate->where($keyName, $value);
                    if (is_string($value)) {
                        $candidate->orWhere('name', $value)->orWhere('label', $value);
                    }
                })
                ->pluck($keyName)
                ->unique()
                ->values();

            if ($matches->count() > 1) {
                throw new RuntimeException("Ambiguous Kinship role reference [{$value}]. Pass a Role model instead.");
            }

            if ($matches->isNotEmpty()) {
                $resolved[] = $matches->first();
            }
        }

        return collect($resolved)->unique()->values()->all();
    }

    /**
     * @param  array<int, mixed>  $groups
     * @return list<int|string>
     */
    protected function resolveGroupIds(array $groups): array
    {
        $groupClass = KinshipModels::group();
        $values = collect($groups)->flatten();
        $configuration = app(WorkspaceConfiguration::class);
        $scope = $this->kinshipAuthorizationContext()->workspaceScope($configuration);
        $keyName = (new $groupClass)->getKeyName();
        $resolved = [];

        if ($scope === null) {
            return [];
        }

        foreach ($values as $value) {
            if ($value instanceof $groupClass) {
                if (! $this->sameKinshipWorkspace($value)) {
                    throw new RuntimeException('Kinship rejected cross-workspace group membership.');
                }

                if ($value->getKey() !== null) {
                    $resolved[] = $value->getKey();
                }

                continue;
            }

            if (! is_int($value) && ! (is_string($value) && trim($value) !== '')) {
                continue;
            }

            $matches = $groupClass::query()
                ->where($configuration->groupForeignKey(), $scope)
                ->where(function (Builder $candidate) use ($keyName, $value): void {
                    $candidate->where($keyName, $value);
                    if (is_string($value)) {
                        foreach ($this->kinshipGroupLookupColumns() as $column) {
                            if ($column !== $keyName) {
                                $candidate->orWhere($column, $value);
                            }
                        }
                    }
                })
                ->pluck($keyName)
                ->unique()
                ->values();

            if ($matches->count() > 1) {
                throw new RuntimeException("Ambiguous Kinship group reference [{$value}]. Pass a Group model instead.");
            }

            if ($matches->isNotEmpty()) {
                $resolved[] = $matches->first();
            }
        }

        return collect($resolved)->unique()->values()->all();
    }

    /**
     * @param  array<int, mixed>  $permissions
     * @return list<int|string>
     */
    protected function resolvePermissionIds(array $permissions): array
    {
        $permissionClass = KinshipModels::permission();
        $values = collect($permissions)->flatten();
        $keyName = (new $permissionClass)->getKeyName();
        $resolved = [];

        foreach ($values as $value) {
            if ($value instanceof $permissionClass) {
                if ($value->getKey() !== null) {
                    $resolved[] = $value->getKey();
                }

                continue;
            }

            if (! is_int($value) && ! (is_string($value) && trim($value) !== '')) {
                continue;
            }

            $matches = $permissionClass::query()
                ->where(function (Builder $candidate) use ($keyName, $value): void {
                    $candidate->where($keyName, $value);
                    if (is_string($value)) {
                        $candidate->orWhere('name', $value)->orWhere('label', $value);
                    }
                })
                ->pluck($keyName)
                ->unique()
                ->values();

            if ($matches->count() > 1) {
                throw new RuntimeException("Ambiguous Kinship permission reference [{$value}]. Pass a Permission model instead.");
            }

            if ($matches->isNotEmpty()) {
                $resolved[] = $matches->first();
            }
        }

        return collect($resolved)->unique()->values()->all();
    }

    private function roleCollectionContains(mixed $needle): bool
    {
        $granted = $this->kinshipEffectiveRoleSet();

        if ($needle instanceof Role) {
            $key = $needle->getKey();

            return $key !== null
                && $this->roleMatchesContext($needle)
                && isset($granted['id:'.(string) $key]);
        }

        if (is_int($needle)) {
            return isset($granted['id:'.(string) $needle]);
        }

        if (is_string($needle)) {
            return isset($granted['id:'.$needle])
                || isset($granted['name:'.$needle])
                || isset($granted['label:'.$needle]);
        }

        return false;
    }

    private function validNeedle(mixed $needle): bool
    {
        return $needle instanceof Role || is_int($needle) || (is_string($needle) && $needle !== '');
    }

    private function validGroupNeedle(mixed $needle): bool
    {
        $groupClass = KinshipModels::group();

        return $needle instanceof $groupClass || is_int($needle) || (is_string($needle) && $needle !== '');
    }

    /** @return list<string> */
    private function kinshipGroupLookupColumns(): array
    {
        $columns = config('kinship.group.lookup_columns', ['name', 'label']);

        if (! is_array($columns)) {
            throw new RuntimeException('Kinship group.lookup_columns must be an array.');
        }

        return collect($columns)
            ->filter(fn (mixed $column): bool => is_string($column) && trim($column) !== '')
            ->map(fn (string $column): string => trim($column))
            ->unique()
            ->values()
            ->all();
    }

    private function roleMatchesContext(Role $role): bool
    {
        return $this->sameKinshipWorkspace($role);
    }

    /**
     * @param  array<int, mixed>  $permissions
     * @return Collection<int, string>
     */
    private function permissionNames(array $permissions): Collection
    {
        return collect($permissions)
            ->flatten()
            ->map(fn (mixed $permission): string => $permission instanceof Permission
                ? (string) $permission->name
                : (is_string($permission) ? $permission : ''))
            ->filter(fn (string $permission): bool => $permission !== '')
            ->unique()
            ->values();
    }

    private function kinshipWorkspaceScope(): string
    {
        $configuration = app(WorkspaceConfiguration::class);
        $scope = $this->kinshipAuthorizationContext()->workspaceScope($configuration);

        if ($scope === null) {
            throw new RuntimeException('Kinship cannot mutate permissions without a resolved workspace context.');
        }

        return $scope;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, array<string, string>>
     */
    private function permissionPivotPayload(array $ids): array
    {
        $scopeKey = app(WorkspaceConfiguration::class)->roleForeignKey();
        $scope = $this->kinshipWorkspaceScope();
        $payload = [];

        foreach ($ids as $id) {
            $payload[$id] = [$scopeKey => $scope];
        }

        return $payload;
    }

    private function kinshipActingRoleSessionKey(): string
    {
        return $this->kinshipActingRoleSessionPrefix().$this->kinshipContextFingerprint();
    }

    private function kinshipActingRoleSessionPrefix(): string
    {
        $prefix = (string) config('kinship.acting_roles.session_prefix', 'kinship.acting_role');

        return $prefix.'.'.str_replace('\\', '_', static::class).'.'.$this->getKey().'.';
    }

    private function kinshipActingRoleMode(): string
    {
        $mode = config('kinship.acting_roles.mode', 'replace');

        if (! is_string($mode) || ! in_array($mode, ['replace', 'merge'], true)) {
            throw new RuntimeException('Kinship acting role mode must be replace or merge.');
        }

        return $mode;
    }

    private function kinshipContextFingerprint(): string
    {
        return $this->kinshipAuthorizationContext()->fingerprint(app(WorkspaceConfiguration::class));
    }

    private function invalidateKinshipSharedCache(): void
    {
        app(PermissionCacheInvalidator::class)->invalidateSubject($this, $this->kinshipAuthorizationContext());
    }

    /** @return list<string> */
    private function resolveKinshipPermissionNames(AuthorizationContext $context): array
    {
        return app(PermissionNameResolver::class)->resolve($this, $context);
    }

    /** @return array<string, true> */
    private function kinshipEffectiveRoleSet(): array
    {
        $acting = $this->getActingRole();
        $tokens = $acting !== null && $this->kinshipActingRoleMode() === 'replace'
            ? []
            : app(EffectiveRoleResolver::class)->resolve($this, $this->kinshipAuthorizationContext());

        if ($acting !== null) {
            $key = $acting->getKey();
            if ($key !== null) {
                $tokens[] = 'id:'.(string) $key;
            }
            if (is_string($acting->name) && $acting->name !== '') {
                $tokens[] = 'name:'.$acting->name;
            }
            if (is_string($acting->label) && $acting->label !== '') {
                $tokens[] = 'label:'.$acting->label;
            }
        }

        return array_fill_keys(array_values(array_unique($tokens)), true);
    }

    /** @return array<string, true> */
    private function kinshipPermissionSet(): array
    {
        return array_fill_keys($this->allPermissionNames()->all(), true);
    }
}
