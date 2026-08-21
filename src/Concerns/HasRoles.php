<?php

namespace Tetranyble\Kinship\Concerns;

/**
 * Compatibility alias for applications migrating an existing HasRoles trait.
 */
trait HasRoles
{
    use HasRolesAndPermissions;
}
