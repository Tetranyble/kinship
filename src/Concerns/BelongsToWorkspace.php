<?php

namespace Tetranyble\Kinship\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tetranyble\Kinship\Contracts\Workspace;
use Tetranyble\Kinship\Support\KinshipModels;
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
        $workspaceModel = KinshipModels::workspace();

        return $this->belongsTo(
            $workspaceModel,
            $configuration->subjectForeignKey(),
            $configuration->workspaceOwnerKey(),
        );
    }
}
