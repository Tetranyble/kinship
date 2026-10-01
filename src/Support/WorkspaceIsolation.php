<?php

namespace Tetranyble\Kinship\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Tetranyble\Kinship\Contracts\Group as GroupContract;

final class WorkspaceIsolation
{
    public function __construct(private readonly WorkspaceConfiguration $workspaces) {}

    public function assertModelsShareScope(Model $first, Model $second, string $operation): void
    {
        $firstScope = $this->scopeOf($first);
        $secondScope = $this->scopeOf($second);

        if ($firstScope === null || $secondScope === null || $firstScope !== $secondScope) {
            throw new RuntimeException("Kinship rejected cross-workspace {$operation}.");
        }
    }

    public function scopeOf(Model $model): ?string
    {
        if ($model instanceof GroupContract) {
            $value = $model->getKinshipWorkspaceIdentifier();

            return (string) $value;
        }

        $value = $model->getAttribute($this->workspaces->roleForeignKey());

        return is_int($value) || is_string($value) ? (string) $value : null;
    }
}
