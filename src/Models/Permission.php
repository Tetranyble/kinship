<?php

namespace Tetranyble\Kinship\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Contracts\Group as GroupContract;
use Tetranyble\Kinship\Database\Factories\PermissionFactory;
use Tetranyble\Kinship\Models\Pivots\GroupPermission;
use Tetranyble\Kinship\Models\Pivots\PermissionRole;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

/** @property string $name */
class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function newFactory(): PermissionFactory
    {
        return PermissionFactory::new();
    }

    protected static function booted(): void
    {
        static::saved(fn (Permission $permission) => $permission->invalidateDefinitions());
        static::deleted(fn (Permission $permission) => $permission->invalidateDefinitions());
        static::restored(fn (Permission $permission) => $permission->invalidateDefinitions());
    }

    protected $fillable = ['name', 'label', 'group'];

    public function getTable(): string
    {
        return (string) config('kinship.tables.permissions', parent::getTable());
    }

    /** @return BelongsToMany<Role, $this, PermissionRole> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            KinshipModels::role(),
            (string) config('kinship.tables.permission_role', 'permission_role'),
            (string) config('kinship.columns.permission_foreign_key', 'permission_id'),
            (string) config('kinship.columns.role_foreign_key', 'role_id'),
        )->using(PermissionRole::class)->withTimestamps();
    }

    /** @return BelongsToMany<Model&GroupContract, $this, GroupPermission> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            KinshipModels::group(),
            (string) config('kinship.tables.group_permission', 'group_permission'),
            (string) config('kinship.columns.permission_foreign_key', 'permission_id'),
            (string) config('kinship.columns.group_foreign_key', 'group_id'),
        )->using(GroupPermission::class)->withTimestamps();
    }

    /** @return array{attached: list<int|string>, detached: list<int|string>, updated: list<int|string>} */
    public function assignRoles(mixed ...$roles): array
    {
        $ids = $this->resolveRoleIds($roles);
        $result = $this->roles()->syncWithoutDetaching($ids);
        $this->invalidateRoleScopes($ids);

        return $result;
    }

    /** @return array{attached: list<int|string>, detached: list<int|string>, updated: list<int|string>} */
    public function syncRoles(mixed ...$roles): array
    {
        $before = $this->roles()->pluck($this->roles()->getRelated()->getQualifiedKeyName())->all();
        $ids = $this->resolveRoleIds($roles);
        $result = $this->roles()->sync($ids);
        $this->invalidateRoleScopes(array_values(array_unique([...$before, ...$ids])));

        return $result;
    }

    /**
     * @param  array<int, mixed>  $roles
     * @return list<int|string>
     */
    private function resolveRoleIds(array $roles): array
    {
        $roleClass = KinshipModels::role();
        $values = collect($roles)->flatten();
        $keyName = (new $roleClass)->getKeyName();
        $resolved = [];

        foreach ($values as $value) {
            if ($value instanceof $roleClass) {
                if ($value->getKey() !== null) {
                    $resolved[] = $value->getKey();
                }

                continue;
            }

            if (! is_int($value) && ! (is_string($value) && trim($value) !== '')) {
                continue;
            }

            $matches = $roleClass::query()
                ->where(function ($candidate) use ($keyName, $value): void {
                    $candidate->where($keyName, $value);
                    if (is_string($value)) {
                        $candidate->orWhere('name', $value)->orWhere('label', $value);
                    }
                })
                ->pluck($keyName)
                ->unique()
                ->values();

            if ($matches->count() > 1) {
                throw new \RuntimeException("Ambiguous Kinship role reference [{$value}]. Pass a Role model instead.");
            }

            if ($matches->isNotEmpty()) {
                $resolved[] = $matches->first();
            }
        }

        return collect($resolved)->unique()->values()->all();
    }

    /** @param list<int|string> $roleIds */
    private function invalidateRoleScopes(array $roleIds): void
    {
        if ($roleIds === []) {
            return;
        }

        $roleClass = KinshipModels::role();
        $scopeKey = app(WorkspaceConfiguration::class)->roleForeignKey();
        $scopes = $roleClass::query()->withTrashed()->whereKey($roleIds)->pluck($scopeKey);

        foreach ($scopes as $scope) {
            if (is_int($scope) || is_string($scope)) {
                app(PermissionCacheInvalidator::class)->invalidateScope((string) $scope);
            }
        }
    }

    private function invalidateDefinitions(): void
    {
        app(PermissionCacheInvalidator::class)->invalidateDefinitions();
    }
}
