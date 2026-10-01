<?php

namespace Tetranyble\Kinship\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Concerns\IsGroup;
use Tetranyble\Kinship\Contracts\Group as GroupContract;

/**
 * Host-owned group model used to prove Kinship does not require inheritance
 * from Tetranyble\Kinship\Models\Group.
 */
class Team extends Model implements GroupContract
{
    use IsGroup;

    protected $table = 'groups';

    protected $guarded = [];
}
