<?php

namespace Tetranyble\Kinship\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Str;

class BasePolicy
{
    use HandlesAuthorization;

    protected ?string $permissionPrefix = null;

    protected function getPermissionPrefix(mixed $modelOrClass = null): string
    {
        if (filled($this->permissionPrefix)) {
            return (string) $this->permissionPrefix;
        }

        $class = is_object($modelOrClass)
            ? $modelOrClass::class
            : (is_string($modelOrClass) && class_exists($modelOrClass) ? $modelOrClass : null);

        return $class ? Str::snake(class_basename($class)) : '';
    }

    protected function permissionName(string $ability, mixed $modelOrClass = null): string
    {
        $prefix = $this->getPermissionPrefix($modelOrClass);

        return $prefix === '' ? $ability : "{$prefix}.{$ability}";
    }

    protected function allowsAbility(mixed $user, string $ability, mixed $modelOrClass = null): bool
    {
        return is_object($user)
            && method_exists($user, 'hasPermissions')
            && $user->hasPermissions($this->permissionName($ability, $modelOrClass));
    }
}
