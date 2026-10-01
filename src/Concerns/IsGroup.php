<?php

namespace Tetranyble\Kinship\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use RuntimeException;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Contracts\Group as GroupContract;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Pivots\GroupPermission;
use Tetranyble\Kinship\Models\Pivots\GroupRole;
use Tetranyble\Kinship\Models\Pivots\GroupUser;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;
use Tetranyble\Kinship\Support\WorkspaceIsolation;

/**
 * Conventional implementation for host-owned Kinship group/access-group models.
 *
 * The host model owns its table, attributes, casts, deletion strategy and
 * business relationships. Kinship requires only a persisted identifier and a
 * workspace identifier. This trait supplies the authorization relationships,
 * grant mutation helpers, workspace immutability and cache invalidation.
 */
trait IsGroup
{
    protected static function bootIsGroup(): void
    {
        static::creating(function (Model&GroupContract $group): void {
            $configuration = app(WorkspaceConfiguration::class);
            $column = $configuration->groupForeignKey();

            if ($group->getAttribute($column) === null) {
                $group->setAttribute($column, $configuration->globalScopeValue);
            }
        });

        static::updating(function (Model&GroupContract $group): void {
            $column = app(WorkspaceConfiguration::class)->groupForeignKey();

            if ($group->isDirty($column)) {
                throw new RuntimeException(
                    'Kinship group workspace scope is immutable. Create a new group in the target workspace instead.'
                );
            }
        });

        static::saved(fn (self $group) => $group->invalidateKinshipGroupCache());
        static::deleted(fn (self $group) => $group->invalidateKinshipGroupCache());

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::registerModelEvent('restored', fn (self $group) => $group->invalidateKinshipGroupCache());
        }
    }

    public function getKinshipGroupIdentifier(): int|string
    {
        $value = $this->getKey();

        if (! is_int($value) && ! is_string($value)) {
            throw new LogicException('A Kinship group must have a persisted identifier.');
        }

        return $value;
    }

    public function getKinshipWorkspaceIdentifier(): int|string
    {
        $value = $this->getAttribute(app(WorkspaceConfiguration::class)->groupForeignKey());

        if (! is_int($value) && ! is_string($value)) {
            throw new LogicException('A Kinship group must have a persisted workspace identifier.');
        }

        return $value;
    }

    /** @return BelongsToMany<Model&Authenticatable, $this, GroupUser> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            KinshipModels::user(),
            (string) config('kinship.tables.group_user', 'group_user'),
            (string) config('kinship.columns.group_foreign_key', 'group_id'),
            (string) config('kinship.columns.user_foreign_key', 'user_id'),
        )->using(GroupUser::class)->withTimestamps();
    }

    /** @return BelongsToMany<Role, $this, GroupRole> */
    public function roles(): BelongsToMany
    {
        $relation = $this->belongsToMany(
            KinshipModels::role(),
            (string) config('kinship.tables.group_role', 'group_role'),
            (string) config('kinship.columns.group_foreign_key', 'group_id'),
            (string) config('kinship.columns.role_foreign_key', 'role_id'),
        )->using(GroupRole::class)->withTimestamps();

        $role = $relation->getRelated();
        $relation->where(
            $role->qualifyColumn(app(WorkspaceConfiguration::class)->roleForeignKey()),
            $this->kinshipGroupWorkspaceScope(),
        );

        return $relation;
    }

    /** @return BelongsToMany<Permission, $this, GroupPermission> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            KinshipModels::permission(),
            (string) config('kinship.tables.group_permission', 'group_permission'),
            (string) config('kinship.columns.group_foreign_key', 'group_id'),
            (string) config('kinship.columns.permission_foreign_key', 'permission_id'),
        )->using(GroupPermission::class)->withTimestamps();
    }

    public function addMembers(mixed ...$members): static
    {
        $ids = $this->resolveKinshipMemberIds($members);

        if ($ids !== []) {
            app(PermissionCacheInvalidator::class)->batch(
                fn () => $this->users()->syncWithoutDetaching($ids),
            );
        }

        return $this;
    }

    public function syncMembers(mixed ...$members): static
    {
        $ids = $this->resolveKinshipMemberIds($members);
        app(PermissionCacheInvalidator::class)->batch(fn () => $this->users()->sync($ids));

        return $this;
    }

    public function removeMembers(mixed ...$members): static
    {
        $ids = $this->resolveKinshipMemberIds($members);

        if ($ids !== []) {
            app(PermissionCacheInvalidator::class)->batch(fn () => $this->users()->detach($ids));
        }

        return $this;
    }

    public function assignRoles(mixed ...$roles): static
    {
        $ids = $this->resolveKinshipRoleIds($roles);

        if ($ids !== []) {
            app(PermissionCacheInvalidator::class)->batch(
                fn () => $this->roles()->syncWithoutDetaching($ids),
            );
        }

        return $this;
    }

    public function syncRoles(mixed ...$roles): static
    {
        $ids = $this->resolveKinshipRoleIds($roles);
        app(PermissionCacheInvalidator::class)->batch(fn () => $this->roles()->sync($ids));

        return $this;
    }

    public function removeRoles(mixed ...$roles): static
    {
        $ids = $this->resolveKinshipRoleIds($roles);

        if ($ids !== []) {
            app(PermissionCacheInvalidator::class)->batch(fn () => $this->roles()->detach($ids));
        }

        return $this;
    }

    public function givePermissionTo(mixed ...$permissions): static
    {
        $ids = $this->resolveKinshipPermissionIds($permissions);

        if ($ids !== []) {
            app(PermissionCacheInvalidator::class)->batch(
                fn () => $this->permissions()->syncWithoutDetaching($ids),
            );
        }

        return $this;
    }

    public function syncPermissions(mixed ...$permissions): static
    {
        $ids = $this->resolveKinshipPermissionIds($permissions);
        app(PermissionCacheInvalidator::class)->batch(fn () => $this->permissions()->sync($ids));

        return $this;
    }

    public function revokePermissionTo(mixed ...$permissions): static
    {
        $ids = $this->resolveKinshipPermissionIds($permissions);

        if ($ids !== []) {
            app(PermissionCacheInvalidator::class)->batch(fn () => $this->permissions()->detach($ids));
        }

        return $this;
    }

    /**
     * @param  Builder<Model&GroupContract>  $query
     * @return Builder<Model&GroupContract>
     */
    public function scopeForWorkspace(Builder $query, int|string|null $workspaceId): Builder
    {
        $configuration = app(WorkspaceConfiguration::class);
        $scope = $configuration->scopeValue($workspaceId, $workspaceId !== null);

        return $query->where($configuration->groupForeignKey(), $scope);
    }

    /**
     * @param  array<int, mixed>  $members
     * @return list<int|string>
     */
    private function resolveKinshipMemberIds(array $members): array
    {
        $userClass = KinshipModels::user();
        $values = collect($members)->flatten();
        $ids = [];

        foreach ($values as $value) {
            $user = $value instanceof $userClass
                ? $value
                : ((is_int($value) || (is_string($value) && trim($value) !== ''))
                    ? $userClass::query()->find($value)
                    : null);

            if (! $user instanceof Model || $user->getKey() === null) {
                continue;
            }

            $ids[] = $user->getKey();
        }

        return collect($ids)->unique()->values()->all();
    }

    /**
     * @param  array<int, mixed>  $roles
     * @return list<int|string>
     */
    private function resolveKinshipRoleIds(array $roles): array
    {
        $roleClass = KinshipModels::role();
        $values = collect($roles)->flatten();
        $keyName = (new $roleClass)->getKeyName();
        $resolved = [];

        foreach ($values as $value) {
            if ($value instanceof $roleClass) {
                app(WorkspaceIsolation::class)->assertModelsShareScope($this, $value, 'group role assignment');
                if ($value->getKey() !== null) {
                    $resolved[] = $value->getKey();
                }

                continue;
            }

            if (! is_int($value) && ! (is_string($value) && trim($value) !== '')) {
                continue;
            }

            $matches = $roleClass::query()
                ->where(app(WorkspaceConfiguration::class)->roleForeignKey(), $this->kinshipGroupWorkspaceScope())
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
     * @param  array<int, mixed>  $permissions
     * @return list<int|string>
     */
    private function resolveKinshipPermissionIds(array $permissions): array
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

    private function kinshipGroupWorkspaceScope(): string
    {
        return (string) $this->getKinshipWorkspaceIdentifier();
    }

    private function invalidateKinshipGroupCache(): void
    {
        $scope = null;

        try {
            $scope = $this->getKinshipWorkspaceIdentifier();
        } catch (LogicException) {
            // An unpersisted/partially-deleted host model has no safe scope to invalidate.
        }

        if (is_int($scope) || is_string($scope)) {
            app(PermissionCacheInvalidator::class)->invalidateScope((string) $scope);
        }
    }
}
