<?php

namespace Tetranyble\Kinship\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Tetranyble\Kinship\Contracts\Workspace;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

trait BelongsToWorkspace
{
    public function getWorkspaceIdentifier(): int|string|null
    {
        $configuration = app(WorkspaceConfiguration::class);
        $value = $this->getAttribute($configuration->subjectForeignKey());

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        $workspace = $this->getRelationValue('kinshipWorkspace');

        return $workspace instanceof Workspace
            ? $workspace->getWorkspaceIdentifier()
            : null;
    }

    /** @return BelongsTo<Model&Workspace, $this> */
    public function kinshipWorkspace(): BelongsTo
    {
        $configuration = app(WorkspaceConfiguration::class);
        $mapping = $configuration->mapping;

        if ($mapping === null) {
            throw new RuntimeException('BelongsToWorkspace requires a complete Kinship workspace mapping.');
        }

        $workspaceModel = $mapping->model;

        if (! is_a($workspaceModel, Workspace::class, true)) {
            throw new RuntimeException('The Kinship workspace model used by BelongsToWorkspace must implement '.Workspace::class.'.');
        }

        return $this->belongsTo(
            $workspaceModel,
            $configuration->subjectForeignKey(),
            $mapping->workspaceOwnerKey,
        );
    }
}
