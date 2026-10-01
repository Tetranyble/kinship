<?php

namespace Tetranyble\Kinship\Models\Pivots;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Events\GroupMemberAdded;
use Tetranyble\Kinship\Events\GroupMemberRemoved;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceIsolation;

final class GroupUser extends Pivot
{
    public $incrementing = false;

    protected static function booted(): void
    {
        self::creating(function (GroupUser $pivot): void {
            $pivot->models();
        });

        self::created(function (GroupUser $pivot): void {
            [$group, $user] = $pivot->models(true);
            $scope = app(WorkspaceIsolation::class)->scopeOf($group);
            if ($scope !== null) {
                app(PermissionCacheInvalidator::class)->invalidateSubjectScope($user, $scope);
                event(new GroupMemberAdded($group->getKey(), $user->getKey(), $scope));
            }
        });

        self::deleted(function (GroupUser $pivot): void {
            [$group, $user] = $pivot->models(true);
            $scope = app(WorkspaceIsolation::class)->scopeOf($group);
            if ($scope !== null) {
                app(PermissionCacheInvalidator::class)->invalidateSubjectScope($user, $scope);
                event(new GroupMemberRemoved($group->getKey(), $user->getKey(), $scope));
            }
        });
    }

    /** @return array{0: Model, 1: Model} */
    private function models(bool $withTrashedGroup = false): array
    {
        $groupKey = (string) config('kinship.columns.group_foreign_key', 'group_id');
        $userKey = (string) config('kinship.columns.user_foreign_key', 'user_id');
        $groupId = $this->getAttribute($groupKey);
        $userId = $this->getAttribute($userKey);

        $groupClass = KinshipModels::group();
        $groupQuery = $groupClass::query();
        if ($withTrashedGroup && in_array(SoftDeletes::class, class_uses_recursive($groupQuery->getModel()), true)) {
            $groupQuery->withTrashed();
        }

        $group = $groupQuery->find($groupId);
        $userClass = KinshipModels::user();
        $user = $userClass::query()->find($userId);

        if (! $group instanceof Model || ! $user instanceof Model) {
            throw new RuntimeException('Kinship group membership references missing models.');
        }

        return [$group, $user];
    }
}
