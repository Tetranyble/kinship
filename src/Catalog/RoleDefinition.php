<?php

namespace Tetranyble\Kinship\Catalog;

final readonly class RoleDefinition
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $name,
        public string $label,
        public ?string $description,
        public int $order,
        public bool $system,
        public array $permissions,
    ) {}
}
