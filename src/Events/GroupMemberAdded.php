<?php

namespace Tetranyble\Kinship\Events;

final readonly class GroupMemberAdded
{
    public function __construct(
        public int|string $groupId,
        public int|string $userId,
        public string $workspaceScope,
    ) {}
}
