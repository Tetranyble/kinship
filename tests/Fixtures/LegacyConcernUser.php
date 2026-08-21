<?php

namespace Tetranyble\Kinship\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tetranyble\Kinship\Concerns\HasRoles;

class LegacyConcernUser extends Authenticatable
{
    use HasRoles;
}
