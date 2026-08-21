<?php

namespace Tetranyble\Kinship\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tetranyble\Kinship\Contracts\WorkspaceSubject;

class WorkspaceUser extends User implements WorkspaceSubject
{
    protected $table = 'users';

    public function getWorkspaceIdentifier(): int|string|null
    {
        $value = $this->getAttribute('workspace_id');

        return is_int($value) || is_string($value) ? $value : null;
    }

    /** @return BelongsTo<Workspace, $this> */
    public function kinshipWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id', 'id');
    }
}
