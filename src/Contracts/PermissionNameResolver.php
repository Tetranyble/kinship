<?php

namespace Tetranyble\Kinship\Contracts;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Support\AuthorizationContext;

interface PermissionNameResolver
{
    /** @return list<string> */
    public function resolve(Model $subject, AuthorizationContext $context): array;
}
