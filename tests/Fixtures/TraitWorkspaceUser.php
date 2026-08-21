<?php

namespace Tetranyble\Kinship\Tests\Fixtures;

use Tetranyble\Kinship\Concerns\BelongsToWorkspace;
use Tetranyble\Kinship\Contracts\WorkspaceSubject;

class TraitWorkspaceUser extends User implements WorkspaceSubject
{
    use BelongsToWorkspace;

    protected $table = 'users';
}
