<?php

namespace Tetranyble\Kinship\Events;

final readonly class GroupRoleAssigned
{
    public function __construct(
        public int|string $groupId,
        public int|string $roleId,
        public string $workspaceScope,
    ) {}
}
