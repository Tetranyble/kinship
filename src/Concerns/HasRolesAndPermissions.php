<?php

namespace Tetranyble\Kinship\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;
use RuntimeException;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Contracts\PermissionNameResolver;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Support\AuthorizationContext;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\ResolvesKinshipContext;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

trait HasRolesAndPermissions
{
    use ResolvesKinshipContext;

    /** @var array<string, Collection<int, Permission>> */
    protected array $kinshipPermissionCache = [];

    /** @var array<string, Collection<int, string>> */
    protected array $kinshipPermissionNameCache = [];

    /** @var array<string, array<string, true>> */
    protected array $kinshipPermissionSetCache = [];

    /** @var array<string, Role|null> */
    protected array $kinshipActingRoleCache = [];

    /** @return BelongsToMany<Role, $this, Pivot> */
    public function roles(): BelongsToMany
    {
        $relation = $this->allRoles();
        $this->scopeKinshipRoleQuery($relation->getQuery());

        return $relation;
    }

    /**
     * Unscoped persistence relationship. Authorization checks must use roles().
     */
    /** @return BelongsToMany<Role, $this, Pivot> */
    public function allRoles(): BelongsToMany
    {
        $roleClass = KinshipModels::role();

        return $this->belongsToMany(
            $roleClass,
            (string) config('kinship.tables.role_user', 'role_user'),
            (string) config('kinship.columns.user_foreign_key', 'user_id'),
            (string) config('kinship.columns.role_foreign_key', 'role_id'),
        )->withTimestamps();
    }

    /** @return BelongsToMany<Permission, $this, Pivot> */
    public function userPermissions(): BelongsToMany
    {
        $permissionClass = KinshipModels::permission();

        return $this->belongsToMany(
            $permissionClass,
            (string) config('kinship.tables.permission_user', 'permission_user'),
            (string) config('kinship.columns.user_foreign_key', 'user_id'),
            (string) config('kinship.columns.permission_foreign_key', 'permission_id'),
        )->withTimestamps();
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
        $contextKey = $this->kinshipContextFingerprint();

        if (array_key_exists($contextKey, $this->kinshipPermissionCache)) {
            return $this->kinshipPermissionCache[$contextKey];
        }

        $names = $this->allPermissionNames();
        if ($names->isEmpty()) {
            return $this->kinshipPermissionCache[$contextKey] = collect();
        }

        $permissionClass = KinshipModels::permission();

        $resolved = [];
        $permissions = $permissionClass::query()
            ->where('guard_name', $this->kinshipGuardName())
            ->whereIn('name', $names->all())
            ->get();

        foreach ($permissions as $permission) {
            if ($permission instanceof Permission) {
                $resolved[] = $permission;
            }
        }

        return $this->kinshipPermissionCache[$contextKey] = collect($resolved);
    }

    /** @return Collection<int, string> */
    public function allPermissionNames(): Collection
    {
        $contextKey = $this->kinshipContextFingerprint();

        if (array_key_exists($contextKey, $this->kinshipPermissionNameCache)) {
            return $this->kinshipPermissionNameCache[$contextKey];
        }

        $context = $this->kinshipAuthorizationContext();
        $acting = $this->getActingRole();
        $names = $acting !== null && $this->kinshipActingRoleMode() === 'replace'
            ? []
            : $this->resolveKinshipPermissionNames($context);

        if ($acting !== null) {
            $actingNames = $acting->permissions()
                ->getQuery()
                ->where('guard_name', $context->guard)
                ->pluck('name');

            foreach ($actingNames as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        return $this->kinshipPermissionNameCache[$contextKey] = collect($names)
            ->unique()
            ->values();
    }

    /** @return Collection<int, string> */
    public function permissions(): Collection
    {
        return $this->allPermissionNames();
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
            $this->allRoles()->syncWithoutDetaching($ids);
            $this->invalidateKinshipSharedCache();
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
        $this->allRoles()->syncWithoutDetaching($ids);

        $detach = array_values(array_diff($currentIds, $ids));
        if ($detach !== []) {
            $this->allRoles()->detach($detach);
        }

        $this->invalidateKinshipSharedCache();
        $this->forgetKinshipCache();

        return $this;
    }

    public function removeRoles(mixed ...$roles): static
    {
        $ids = $this->resolveRoleIds($roles);

        if ($ids !== []) {
            $this->allRoles()->detach($ids);
            $this->invalidateKinshipSharedCache();
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
            $this->userPermissions()->syncWithoutDetaching($ids);
            $this->invalidateKinshipSharedCache();
            $this->forgetKinshipCache();
        }

        return $this;
    }

    public function syncPermissions(mixed ...$permissions): static
    {
        $this->userPermissions()->sync($this->resolvePermissionIds($permissions));
        $this->invalidateKinshipSharedCache();
        $this->forgetKinshipCache();

        return $this;
    }

    public function removePermissions(mixed ...$permissions): static
    {
        $ids = $this->resolvePermissionIds($permissions);

        if ($ids !== []) {
            $this->userPermissions()->detach($ids);
            $this->invalidateKinshipSharedCache();
            $this->forgetKinshipCache();
        }

        return $this;
    }

    public function getActingRole(): ?Role
    {
        if (! (bool) config('kinship.acting_roles.enabled', true)) {
            return null;
        }

        $contextKey = $this->kinshipContextFingerprint();

        if (array_key_exists($contextKey, $this->kinshipActingRoleCache)) {
            return $this->kinshipActingRoleCache[$contextKey];
        }

        if (! app()->bound('session')) {
            return null;
        }

        $roleId = session($this->kinshipActingRoleSessionKey());
        if ($roleId === null) {
            return null;
        }

        $roleClass = KinshipModels::role();
        /** @var Role|null $role */
        $role = $this->scopeKinshipRoleQuery($roleClass::query())->find($roleId);

        return $this->kinshipActingRoleCache[$contextKey] = $role;
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

        if (! (bool) config('kinship.acting_roles.allow_unassigned', false)
            && ! $this->roles()->getQuery()->whereKey($roleId)->exists()) {
            return false;
        }

        if (app()->bound('session')) {
            session([$this->kinshipActingRoleSessionKey() => $roleId]);
        }

        $roleClass = KinshipModels::role();
        $contextKey = $this->kinshipContextFingerprint();
        $actingRole = $this->scopeKinshipRoleQuery($roleClass::query())
            ->whereKey($roleId)
            ->first();
        $this->kinshipActingRoleCache[$contextKey] = $actingRole instanceof Role ? $actingRole : null;
        $this->forgetKinshipCache(keepActingRole: true);

        return $this->kinshipActingRoleCache[$contextKey] !== null;
    }

    public function stopActingAs(): void
    {
        if (app()->bound('session')) {
            session()->forget($this->kinshipActingRoleSessionKey());
        }

        unset($this->kinshipActingRoleCache[$this->kinshipContextFingerprint()]);
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

    protected function forgetKinshipCache(bool $keepActingRole = false): void
    {
        $this->kinshipPermissionCache = [];
        $this->kinshipPermissionNameCache = [];
        $this->kinshipPermissionSetCache = [];
        $this->unsetRelation('roles');
        $this->unsetRelation('userPermissions');

        if (! $keepActingRole) {
            $this->kinshipActingRoleCache = [];
        }
    }

    /**
     * @param  array<int, mixed>  $roles
     * @return list<int|string>
     */
    protected function resolveRoleIds(array $roles): array
    {
        $roleClass = KinshipModels::role();
        $values = collect($roles)->flatten();
        if ($values->isEmpty()) {
            return [];
        }

        $ids = $values
            ->filter(fn (mixed $value): bool => $value instanceof $roleClass)
            ->filter(fn (Role $value): bool => $value->guard_name === $this->kinshipGuardName() && $this->sameKinshipWorkspace($value))
            ->map(fn (Role $value): mixed => $value->getKey());

        $candidateIds = $values->filter(fn (mixed $value): bool => is_int($value) || is_string($value));
        $names = $values->filter(fn (mixed $value): bool => is_string($value));

        $query = $this->scopeKinshipRoleQuery($roleClass::query());
        $keyName = (new $roleClass)->getKeyName();

        return $query
            ->where(function (Builder $query) use ($candidateIds, $names, $keyName): void {
                $query->whereIn($keyName, $candidateIds->all())
                    ->orWhereIn('name', $names->all())
                    ->orWhereIn('label', $names->all());
            })
            ->pluck($keyName)
            ->merge($ids)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $permissions
     * @return list<int|string>
     */
    protected function resolvePermissionIds(array $permissions): array
    {
        $permissionClass = KinshipModels::permission();
        $values = collect($permissions)->flatten();
        if ($values->isEmpty()) {
            return [];
        }

        $ids = $values
            ->filter(fn (mixed $value): bool => $value instanceof $permissionClass)
            ->filter(fn (Permission $value): bool => $value->guard_name === $this->kinshipGuardName())
            ->map(fn (Permission $value): mixed => $value->getKey());

        $candidateIds = $values->filter(fn (mixed $value): bool => is_int($value) || is_string($value));
        $names = $values->filter(fn (mixed $value): bool => is_string($value));
        $keyName = (new $permissionClass)->getKeyName();

        return $permissionClass::query()
            ->where('guard_name', $this->kinshipGuardName())
            ->where(function (Builder $query) use ($candidateIds, $names, $keyName): void {
                $query->whereIn($keyName, $candidateIds->all())
                    ->orWhereIn('name', $names->all())
                    ->orWhereIn('label', $names->all());
            })
            ->pluck($keyName)
            ->merge($ids)
            ->unique()
            ->values()
            ->all();
    }

    private function roleCollectionContains(mixed $needle): bool
    {
        $acting = $this->getActingRole();
        $roles = $acting !== null && $this->kinshipActingRoleMode() === 'replace'
            ? []
            : $this->roles()->getQuery()->get()->all();

        if ($acting !== null) {
            $roles[] = $acting;
        }

        foreach ($roles as $role) {
            if (! $role instanceof Role) {
                continue;
            }

            if ($needle instanceof Role) {
                if ($needle->is($role)) {
                    return true;
                }

                continue;
            }

            if (is_int($needle) || is_string($needle)) {
                if ((string) $needle === (string) $role->getKey()) {
                    return true;
                }

                if (is_int($needle)) {
                    continue;
                }
            }

            if (is_string($needle) && ($needle === $role->name || $needle === $role->label)) {
                return true;
            }
        }

        return false;
    }

    private function validNeedle(mixed $needle): bool
    {
        return $needle instanceof Role || is_int($needle) || (is_string($needle) && $needle !== '');
    }

    private function roleMatchesContext(Role $role): bool
    {
        return $role->guard_name === $this->kinshipGuardName()
            && $this->sameKinshipWorkspace($role);
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
                ? ($permission->guard_name === $this->kinshipGuardName() ? (string) $permission->name : '')
                : (is_string($permission) ? $permission : ''))
            ->filter(fn (string $permission): bool => $permission !== '')
            ->unique()
            ->values();
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
        app(PermissionCacheInvalidator::class)->invalidateSubject($this, $this->kinshipGuardName());
    }

    /** @return list<string> */
    private function resolveKinshipPermissionNames(AuthorizationContext $context): array
    {
        return app(PermissionNameResolver::class)->resolve($this, $context);
    }

    /** @return array<string, true> */
    private function kinshipPermissionSet(): array
    {
        $contextKey = $this->kinshipContextFingerprint();

        if (! array_key_exists($contextKey, $this->kinshipPermissionSetCache)) {
            $this->kinshipPermissionSetCache[$contextKey] = array_fill_keys(
                $this->allPermissionNames()->all(),
                true,
            );
        }

        return $this->kinshipPermissionSetCache[$contextKey];
    }
}
