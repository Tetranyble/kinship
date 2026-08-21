<?php

namespace Tetranyble\Kinship\Contracts;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Support\AuthorizationContext;

interface AuthorizationContextResolver
{
    public function resolve(Model $subject, string $guard): AuthorizationContext;
}
