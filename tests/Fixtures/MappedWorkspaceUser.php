<?php

namespace Tetranyble\Kinship\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MappedWorkspaceUser extends User
{
    protected $table = 'users';

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'organization_id', 'id');
    }
}
