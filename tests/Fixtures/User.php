<?php

namespace Tetranyble\Kinship\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tetranyble\Kinship\Concerns\HasRolesAndPermissions;

class User extends Authenticatable
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasRolesAndPermissions;

    protected $guarded = [];
}
