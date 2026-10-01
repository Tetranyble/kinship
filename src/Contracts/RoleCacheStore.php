<?php

namespace Tetranyble\Kinship\Contracts;

interface RoleCacheStore
{
    /** @return list<string>|null */
    public function roles(string $key): ?array;

    /** @param list<string> $roles */
    public function putRoles(string $key, array $roles, int $seconds): void;
}
