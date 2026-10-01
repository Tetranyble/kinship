<?php

namespace Tetranyble\Kinship\Events;

final readonly class GroupRoleRevoked
{
    public function __construct(
        public int|string $groupId,
        public int|string $roleId,
        public string $workspaceScope,
    ) {}
}
