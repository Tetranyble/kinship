<?php

namespace Tetranyble\Kinship\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use RuntimeException;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Contracts\Group as GroupContract;
use Tetranyble\Kinship\Database\Factories\RoleFactory;
use Tetranyble\Kinship\Models\Pivots\GroupRole;
use Tetranyble\Kinship\Models\Pivots\PermissionRole;
use Tetranyble\Kinship\Models\Pivots\RoleUser;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

/**
 * @property string $name
 * @property string|null $label
 * @property-read Collection<int, Permission> $permissions
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Role $role): void {
            $configuration = app(WorkspaceConfiguration::class);
            $column = $configuration->roleForeignKey();

            if ($role->getAttribute($column) === null) {
                $role->setAttribute($column, $configuration->globalScopeValue);
            }
        });

        static::updating(function (Role $role): void {
            $scopeKey = app(WorkspaceConfiguration::class)->roleForeignKey();
            if ($role->isDirty($scopeKey)) {
                throw new RuntimeException('Kinship role workspace scope is immutable. Create a new role in the target workspace instead.');
            }

        });

        static::saved(fn (Role $role) => $role->invalidateKinshipCache());
        static::deleted(fn (Role $role) => $role->invalidateKinshipCache());
        static::restored(fn (Role $role) => $role->invalidateKinshipCache());
    }

    protected $fillable = [
        'name',
        'label',
        'description',
        'order',
        'is_system',
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

    /** @return BelongsToMany<Permission, $this, PermissionRole> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            KinshipModels::permission(),
            (string) config('kinship.tables.permission_role', 'permission_role'),
            (string) config('kinship.columns.role_foreign_key', 'role_id'),
            (string) config('kinship.columns.permission_foreign_key', 'permission_id'),
        )->using(PermissionRole::class)->withTimestamps();
    }

    /** @return BelongsToMany<Model&GroupContract, $this, GroupRole> */
    public function groups(): BelongsToMany
    {
        $relation = $this->belongsToMany(
            KinshipModels::group(),
            (string) config('kinship.tables.group_role', 'group_role'),
            (string) config('kinship.columns.role_foreign_key', 'role_id'),
            (string) config('kinship.columns.group_foreign_key', 'group_id'),
        )->using(GroupRole::class)->withTimestamps();

        $group = $relation->getRelated();
        $relation->where(
            $group->qualifyColumn(app(WorkspaceConfiguration::class)->groupForeignKey()),
            $this->getAttribute(app(WorkspaceConfiguration::class)->roleForeignKey()),
        );

        return $relation;
    }

    /** @return BelongsToMany<Model&Authenticatable, $this, RoleUser> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            KinshipModels::user(),
            (string) config('kinship.tables.role_user', 'role_user'),
            (string) config('kinship.columns.role_foreign_key', 'role_id'),
            (string) config('kinship.columns.user_foreign_key', 'user_id'),
        )->using(RoleUser::class)->withTimestamps();
    }

    public function givePermissionTo(mixed ...$permissions): static
    {
        $ids = $this->resolvePermissionIds($permissions);
        if ($ids !== []) {
            $this->permissions()->syncWithoutDetaching($ids);
            $this->invalidateKinshipCache();
        }

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
        $ids = $this->resolvePermissionIds($permissions);
        if ($ids !== []) {
            $this->permissions()->detach($ids);
            $this->invalidateKinshipCache();
        }

        return $this;
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

    private function invalidateKinshipCache(): void
    {
        $this->invalidateKinshipCacheFor(
            $this->getAttribute(app(WorkspaceConfiguration::class)->roleForeignKey()),
        );
    }

    private function invalidateKinshipCacheFor(mixed $scope): void
    {
        if (is_int($scope) || is_string($scope)) {
            app(PermissionCacheInvalidator::class)->invalidateScope((string) $scope);
        }
    }
}
