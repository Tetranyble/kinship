<?php

namespace Tetranyble\Kinship\Contracts;

use Illuminate\Database\Eloquent\Model;

interface GuardResolver
{
    public function resolve(?Model $subject = null, ?string $requestedGuard = null): string;
}
