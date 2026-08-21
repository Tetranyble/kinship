<?php

namespace Tetranyble\Kinship\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Contracts\GuardResolver;
use Tetranyble\Kinship\Support\KinshipModels;

/**
 * @property string $guard_name
 * @property string $name
 */
class Permission extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Permission $permission): void {
            if (! is_string($permission->getAttribute('guard_name')) || trim($permission->getAttribute('guard_name')) === '') {
                $permission->setAttribute('guard_name', app(GuardResolver::class)->resolve());
            }
        });

        static::updating(function (Permission $permission): void {
            $guard = $permission->getOriginal('guard_name');
            if (is_string($guard) && $guard !== '') {
                app(PermissionCacheInvalidator::class)->invalidateGuard($guard);
            }
        });

        static::saved(function (Permission $permission): void {
            $permission->invalidateKinshipCache();
        });
        static::deleted(function (Permission $permission): void {
            $permission->invalidateKinshipCache();
        });
        static::restored(function (Permission $permission): void {
            $permission->invalidateKinshipCache();
        });
    }

    protected $fillable = [
        'name',
        'label',
        'group',
        'guard_name',
    ];

    public function getTable(): string
    {
        return (string) config('kinship.tables.permissions', parent::getTable());
    }

    /** @return BelongsToMany<Role, $this, Pivot> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            KinshipModels::role(),
            (string) config('kinship.tables.permission_role', 'permission_role'),
            (string) config('kinship.columns.permission_foreign_key', 'permission_id'),
            (string) config('kinship.columns.role_foreign_key', 'role_id'),
        )->withTimestamps();
    }

    /**
     * Attach roles to this global permission without removing existing roles.
     *
     * Role models and collections are accepted for compatibility. Numeric
     * identifiers are guard-validated before they are attached.
     *
     * @return array{attached: list<int|string>, detached: list<int|string>, updated: list<int|string>}
     */
    public function assignRoles(mixed ...$roles): array
    {
        return $this->roles()->syncWithoutDetaching($this->resolveRoleIds($roles));
    }

    /** @return array{attached: list<int|string>, detached: list<int|string>, updated: list<int|string>} */
    public function syncRoles(mixed ...$roles): array
    {
        return $this->roles()->sync($this->resolveRoleIds($roles));
    }

    /**
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeForGuard(Builder $query, ?string $guard = null): Builder
    {
        return $query->where('guard_name', app(GuardResolver::class)->resolve(requestedGuard: $guard));
    }

    /**
     * @param  array<int, mixed>  $roles
     * @return list<int|string>
     */
    private function resolveRoleIds(array $roles): array
    {
        $roleClass = KinshipModels::role();
        $values = collect($roles)->flatten();
        $ids = $values
            ->filter(fn (mixed $value): bool => $value instanceof $roleClass)
            ->filter(fn (Role $value): bool => $value->guard_name === $this->guard_name)
            ->map(fn (Role $value): mixed => $value->getKey());
        $candidateIds = $values->filter(
            fn (mixed $value): bool => is_int($value) || is_string($value),
        );

        return $roleClass::query()
            ->where('guard_name', $this->guard_name)
            ->whereKey($candidateIds->all())
            ->pluck((new $roleClass)->getKeyName())
            ->merge($ids)
            ->unique()
            ->values()
            ->all();
    }

    private function invalidateKinshipCache(): void
    {
        if (is_string($this->guard_name) && $this->guard_name !== '') {
            app(PermissionCacheInvalidator::class)->invalidateGuard($this->guard_name);
        }
    }
}
