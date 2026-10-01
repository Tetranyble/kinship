<?php

namespace Tetranyble\Kinship\Models\Pivots;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

final class RoleUser extends Pivot
{
    public $incrementing = false;

    protected static function booted(): void
    {
        self::creating(function (RoleUser $pivot): void {
            $roleKey = (string) config('kinship.columns.role_foreign_key', 'role_id');
            $userKey = (string) config('kinship.columns.user_foreign_key', 'user_id');
            $roleClass = KinshipModels::role();
            $userClass = KinshipModels::user();

            if (! $roleClass::query()->whereKey($pivot->getAttribute($roleKey))->exists()
                || ! $userClass::query()->whereKey($pivot->getAttribute($userKey))->exists()) {
                throw new \RuntimeException('Kinship user role assignment references missing or deleted models.');
            }
        });

        $invalidate = function (RoleUser $pivot): void {
            $roleKey = (string) config('kinship.columns.role_foreign_key', 'role_id');
            $userKey = (string) config('kinship.columns.user_foreign_key', 'user_id');
            $roleId = $pivot->getAttribute($roleKey);
            $userId = $pivot->getAttribute($userKey);

            if ((! is_int($roleId) && ! is_string($roleId))
                || (! is_int($userId) && ! is_string($userId))) {
                return;
            }

            $roleClass = KinshipModels::role();
            $scopeKey = app(WorkspaceConfiguration::class)->roleForeignKey();
            $scope = $roleClass::query()->withTrashed()->whereKey($roleId)->value($scopeKey);
            $userClass = KinshipModels::user();
            $user = $userClass::query()->find($userId);

            if ((is_int($scope) || is_string($scope)) && $user instanceof Model) {
                app(PermissionCacheInvalidator::class)->invalidateSubjectScope($user, (string) $scope);
            }
        };

        self::saved($invalidate);
        self::deleted($invalidate);
    }
}
