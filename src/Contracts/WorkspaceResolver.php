<?php

namespace Tetranyble\Kinship\Contracts;

use Illuminate\Database\Eloquent\Model;

interface WorkspaceResolver
{
    public function resolve(Model $subject): int|string|null;
}
