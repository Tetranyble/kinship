<?php

namespace Tetranyble\Kinship\Contracts;

use Tetranyble\Kinship\Catalog\PermissionDefinition;
use Tetranyble\Kinship\Catalog\RoleDefinition;

interface PermissionCatalog
{
    /** @return list<PermissionDefinition> */
    public function permissions(): array;

    /** @return list<RoleDefinition> */
    public function roles(): array;
}
