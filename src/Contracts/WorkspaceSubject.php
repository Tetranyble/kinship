<?php

namespace Tetranyble\Kinship\Contracts;

interface WorkspaceSubject
{
    public function getWorkspaceIdentifier(): int|string|null;
}
