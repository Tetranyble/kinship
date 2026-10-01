<?php

namespace Tetranyble\Kinship\Events;

final readonly class GroupMemberRemoved
{
    public function __construct(
        public int|string $groupId,
        public int|string $userId,
        public string $workspaceScope,
    ) {}
}
