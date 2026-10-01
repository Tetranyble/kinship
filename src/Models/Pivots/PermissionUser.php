<?php

namespace Tetranyble\Kinship\Models\Pivots;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

final class PermissionUser extends Pivot
{
    public $incrementing = false;

    protected static function booted(): void
    {
        self::creating(function (PermissionUser $pivot): void {
            $permissionKey = (string) config('kinship.columns.permission_foreign_key', 'permission_id');
            $userKey = (string) config('kinship.columns.user_foreign_key', 'user_id');
            $scope = $pivot->getAttribute(app(WorkspaceConfiguration::class)->roleForeignKey());
            $permissionClass = KinshipModels::permission();
            $userClass = KinshipModels::user();

            if ((! is_int($scope) && ! is_string($scope))
                || ! $permissionClass::query()->whereKey($pivot->getAttribute($permissionKey))->exists()
                || ! $userClass::query()->whereKey($pivot->getAttribute($userKey))->exists()) {
                throw new \RuntimeException('Kinship direct permission assignment references an invalid subject, permission, or workspace scope.');
            }
        });

        $invalidate = function (PermissionUser $pivot): void {
            $scope = $pivot->getAttribute(app(WorkspaceConfiguration::class)->roleForeignKey());
            $userKey = (string) config('kinship.columns.user_foreign_key', 'user_id');
            $userId = $pivot->getAttribute($userKey);

            if ((! is_int($scope) && ! is_string($scope))
                || (! is_int($userId) && ! is_string($userId))) {
                return;
            }

            $userClass = KinshipModels::user();
            $user = $userClass::query()->find($userId);
            if ($user instanceof Model) {
                app(PermissionCacheInvalidator::class)->invalidateSubjectScope($user, (string) $scope);
            }
        };

        self::saved($invalidate);
        self::deleted($invalidate);
    }
}
