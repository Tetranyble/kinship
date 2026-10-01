<?php

namespace Tetranyble\Kinship\Contracts;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Support\AuthorizationContext;

interface EffectiveRoleResolver
{
    /** @return list<string> */
    public function resolve(Model $subject, AuthorizationContext $context): array;
}
