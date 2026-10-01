<?php

namespace Tetranyble\Kinship\Contracts;

interface Group
{
    public function getKinshipGroupIdentifier(): int|string;

    public function getKinshipWorkspaceIdentifier(): int|string;
}
