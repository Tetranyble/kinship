<?php

namespace Tetranyble\Kinship\Catalog;

final readonly class CatalogSeedResult
{
    public function __construct(
        public int $permissions,
        public int $roles,
        public int $assignments,
        public int $attached,
        public int $detached,
        public int $restored,
        public bool $dryRun,
    ) {}
}
