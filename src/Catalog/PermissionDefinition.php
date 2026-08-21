<?php

namespace Tetranyble\Kinship\Catalog;

final readonly class PermissionDefinition
{
    public function __construct(
        public string $name,
        public string $label,
        public string $group,
    ) {}
}
