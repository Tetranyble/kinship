<?php

namespace Tetranyble\Kinship\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface ImpersonationAuthorizer
{
    public function authorize(Authenticatable $actor, Authenticatable $target, string $guard): bool;
}
