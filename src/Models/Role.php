<?php

namespace Tetranyble\Kinship\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Contracts\GuardResolver;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

/**
 * @property string $guard_name
 * @property string $name
 * @property string|null $label
 * @property-read Collection<int, Permission> $permissions
 */
class Role extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Role $role): void {
            $configuration = app(WorkspaceConfiguration::class);
            $column = $configuration->roleForeignKey();

            if (! is_string($role->getAttribute('guard_name')) || trim($role->getAttribute('guard_name')) === '') {
                $role->setAttribute('guard_name', app(GuardResolver::class)->resolve());
            }

            if ($role->getAttribute($column) === null) {
                $role->setAttribute($column, $configuration->globalScopeValue);
            }
        });

        static::updating(function (Role $role): void {
            $guard = $role->getOriginal('guard_name');
            $scope = $role->getOriginal(app(WorkspaceConfiguration::class)->roleForeignKey());
            $role->invalidateKinshipCacheFor($guard, $scope);
        });

        static::saved(function (Role $role): void {
            $role->invalidateKinshipCache();
        });
        static::deleted(function (Role $role): void {
            $role->invalidateKinshipCache();
        });
        static::restored(function (Role $role): void {
            $role->invalidateKinshipCache();
        });
    }

    protected $fillable = [
        'name',
        'label',
        'description',
        'order',
        'is_system',
        'guard_name',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_system' => 'boolean',
        'order' => 'integer',
    ];

    public function getTable(): string
    {
        return (string) config('kinship.tables.roles', parent::getTable());
    }

    public function getFillable(): array
    {
        $fillable = parent::getFillable();

        $fillable[] = app(WorkspaceConfiguration::class)->roleForeignKey();

        return array_values(array_unique($fillable));
    }

    /** @return BelongsToMany<Permission, $this, Pivot> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            KinshipModels::permission(),
            (string) config('kinship.tables.permission_role', 'permission_role'),
            (string) config('kinship.columns.role_foreign_key', 'role_id'),
            (string) config('kinship.columns.permission_foreign_key', 'permission_id'),
        )->withTimestamps();
    }

    /** @return BelongsToMany<Model&Authenticatable, $this, Pivot> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            KinshipModels::user(),
            (string) config('kinship.tables.role_user', 'role_user'),
            (string) config('kinship.columns.role_foreign_key', 'role_id'),
            (string) config('kinship.columns.user_foreign_key', 'user_id'),
        )->withTimestamps();
    }

    public function givePermissionTo(mixed ...$permissions): static
    {
        $ids = $this->resolvePermissionIds($permissions);
        $this->permissions()->syncWithoutDetaching($ids);
        $this->invalidateKinshipCache();

        return $this;
    }

    public function syncPermissions(mixed ...$permissions): static
    {
        $this->permissions()->sync($this->resolvePermissionIds($permissions));
        $this->invalidateKinshipCache();

        return $this;
    }

    public function revokePermissionTo(mixed ...$permissions): static
    {
        $this->permissions()->detach($this->resolvePermissionIds($permissions));
        $this->invalidateKinshipCache();

        return $this;
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeForGuard(Builder $query, ?string $guard = null): Builder
    {
        return $query->where('guard_name', app(GuardResolver::class)->resolve(requestedGuard: $guard));
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeForWorkspace(Builder $query, int|string|null $workspaceId): Builder
    {
        $configuration = app(WorkspaceConfiguration::class);
        $scope = $configuration->scopeValue($workspaceId, $workspaceId !== null);

        return $query->where($configuration->roleForeignKey(), $scope);
    }

    /**
     * @param  array<int, mixed>  $permissions
     * @return list<int|string>
     */
    private function resolvePermissionIds(array $permissions): array
    {
        $permissionClass = KinshipModels::permission();
        $values = collect($permissions)->flatten();
        $ids = $values
            ->filter(fn (mixed $value): bool => $value instanceof $permissionClass)
            ->filter(fn (Model $value): bool => $value->getAttribute('guard_name') === $this->guard_name)
            ->map(fn (Model $value): mixed => $value->getKey());

        $numericIds = $values->filter(fn (mixed $value): bool => is_int($value) || (is_string($value) && ctype_digit($value)));
        $names = $values->filter(fn (mixed $value): bool => is_string($value) && ! ctype_digit($value));

        return $permissionClass::query()
            ->where('guard_name', $this->guard_name)
            ->where(function (Builder $query) use ($numericIds, $names): void {
                $query->whereIn($query->getModel()->getQualifiedKeyName(), $numericIds->all())
                    ->orWhereIn('name', $names->all())
                    ->orWhereIn('label', $names->all());
            })
            ->pluck((new $permissionClass)->getKeyName())
            ->merge($ids)
            ->unique()
            ->values()
            ->all();
    }

    private function invalidateKinshipCache(): void
    {
        $configuration = app(WorkspaceConfiguration::class);
        $this->invalidateKinshipCacheFor(
            $this->getAttribute('guard_name'),
            $this->getAttribute($configuration->roleForeignKey()),
        );
    }

    private function invalidateKinshipCacheFor(mixed $guard, mixed $scope): void
    {
        if (is_string($guard) && $guard !== '' && (is_int($scope) || is_string($scope))) {
            app(PermissionCacheInvalidator::class)->invalidateScope($guard, (string) $scope);
        }
    }
}
