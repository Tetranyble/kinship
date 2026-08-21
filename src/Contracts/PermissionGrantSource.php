<?php

namespace Tetranyble\Kinship\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Tetranyble\Kinship\Support\AuthorizationContext;

interface PermissionGrantSource
{
    /**
     * Return a query selecting one string column aliased as `name`, or null
     * when this source is disabled for the application.
     */
    public function query(Model $subject, AuthorizationContext $context): ?Builder;
}
