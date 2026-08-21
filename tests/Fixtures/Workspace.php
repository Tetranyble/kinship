<?php

namespace Tetranyble\Kinship\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Concerns\IsWorkspace;
use Tetranyble\Kinship\Contracts\Workspace as WorkspaceContract;

class Workspace extends Model implements WorkspaceContract
{
    use IsWorkspace;

    protected $guarded = [];
}
