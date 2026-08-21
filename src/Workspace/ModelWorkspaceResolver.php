<?php

namespace Tetranyble\Kinship\Workspace;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Tetranyble\Kinship\Contracts\Workspace;
use Tetranyble\Kinship\Contracts\WorkspaceResolver;
use Tetranyble\Kinship\Contracts\WorkspaceSubject;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

class ModelWorkspaceResolver implements WorkspaceResolver
{
    public function __construct(private readonly WorkspaceConfiguration $configuration) {}

    public function resolve(Model $subject): int|string|null
    {
        if ($subject instanceof WorkspaceSubject) {
            $identifier = $subject->getWorkspaceIdentifier();

            if ($identifier !== null) {
                return $identifier;
            }

            if (! method_exists($subject, 'kinshipWorkspace')) {
                return null;
            }

            $workspace = $subject->getRelationValue('kinshipWorkspace');

            if ($workspace === null) {
                return null;
            }

            if (! $workspace instanceof Workspace) {
                throw new RuntimeException('The Kinship workspace subject relationship must return a model implementing '.Workspace::class.'.');
            }

            return $workspace->getWorkspaceIdentifier();
        }

        if ($this->configuration->mapping !== null) {
            $mapping = $this->configuration->mapping;
            $subjectForeignKey = $mapping->subjectForeignKey;
            $relationship = $mapping->relationship;
            $workspaceModel = $mapping->model;
            $workspaceOwnerKey = $mapping->workspaceOwnerKey;
            $value = $subject->getAttribute($subjectForeignKey);

            if (is_int($value) || is_string($value)) {
                return $value;
            }

            $workspace = $subject->getRelationValue($relationship);

            if ($workspace === null) {
                return null;
            }

            if (! $workspace instanceof $workspaceModel) {
                throw new RuntimeException("The mapped Kinship workspace relationship [{$relationship}] returned an invalid model.");
            }

            $value = $workspace->getAttribute($workspaceOwnerKey);

            return is_int($value) || is_string($value) ? $value : null;
        }

        $value = $subject->getAttribute($this->configuration->subjectForeignKey());

        return is_int($value) || is_string($value) ? $value : null;
    }
}
