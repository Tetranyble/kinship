<?php

namespace Tetranyble\Kinship\Events;

final readonly class GroupPermissionGranted
{
    public function __construct(
        public int|string $groupId,
        public int|string $permissionId,
        public string $workspaceScope,
    ) {}
}
