<?php

namespace Tetranyble\Kinship\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Contracts\WorkspaceResolver;

final class FixedWorkspaceResolver implements WorkspaceResolver
{
    public function resolve(Model $subject): string
    {
        return 'workspace-from-request';
    }
}
