<?php

namespace Tetranyble\Kinship\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tetranyble\Kinship\Contracts\PermissionGrantSource;
use Tetranyble\Kinship\Support\AuthorizationContext;

final class TeamPermissionGrantSource implements PermissionGrantSource
{
    public function query(Model $subject, AuthorizationContext $context): Builder
    {
        return DB::table('team_user as tu')
            ->join('permission_team as pt', 'pt.team_id', '=', 'tu.team_id')
            ->join('permissions as p', 'p.id', '=', 'pt.permission_id')
            ->where('tu.user_id', $subject->getKey())
            ->where('p.guard_name', $context->guard)
            ->whereNull('p.deleted_at')
            ->select('p.name as name');
    }
}
