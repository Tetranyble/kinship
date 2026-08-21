<?php

namespace Tetranyble\Kinship\Contracts;

interface PermissionCacheStore
{
    /** @return list<string>|null */
    public function permissions(string $key): ?array;

    /** @param list<string> $permissions */
    public function putPermissions(string $key, array $permissions, int $seconds): void;

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public function versions(array $keys): array;

    public function rotate(string $key): void;
}
