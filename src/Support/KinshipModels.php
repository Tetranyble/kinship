<?php

namespace Tetranyble\Kinship\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;

final class KinshipModels
{
    /** @return class-string<Role> */
    public static function role(): string
    {
        return self::configuredModel('role', Role::class, Role::class);
    }

    /** @return class-string<Permission> */
    public static function permission(): string
    {
        return self::configuredModel('permission', Permission::class, Permission::class);
    }

    /** @return class-string<Model&Authenticatable> */
    public static function user(): string
    {
        $configured = config('kinship.models.user');

        if (! is_string($configured) || $configured === '') {
            $provider = (string) config('auth.defaults.provider', 'users');
            $configured = config("auth.providers.{$provider}.model")
                ?? config('auth.providers.users.model');
        }

        if (! is_string($configured)
            || ! is_a($configured, Model::class, true)
            || ! is_a($configured, Authenticatable::class, true)) {
            throw new RuntimeException('Kinship requires an Eloquent user model in kinship.models.user.');
        }

        /** @var class-string<Model&Authenticatable> $configured */
        return $configured;
    }

    /** @return class-string */
    private static function configuredModel(string $key, string $fallback, string $expected): string
    {
        $configured = config("kinship.models.{$key}", $fallback);

        if (! is_string($configured) || ! is_a($configured, $expected, true)) {
            throw new RuntimeException("The configured Kinship {$key} model must extend {$expected}.");
        }

        return $configured;
    }
}
