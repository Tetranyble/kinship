<?php

namespace Tetranyble\Kinship\Workspace;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Contracts\AuthorizationContextResolver;
use Tetranyble\Kinship\Contracts\WorkspaceResolver;
use Tetranyble\Kinship\Support\AuthorizationContext;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

final class ModelAuthorizationContextResolver implements AuthorizationContextResolver
{
    public function __construct(
        private readonly WorkspaceResolver $workspaces,
        private readonly WorkspaceConfiguration $configuration,
    ) {}

    public function resolve(Model $subject, string $guard): AuthorizationContext
    {
        $workspaceEnabled = $this->configuration->enabledFor($subject);

        return new AuthorizationContext(
            $guard,
            $workspaceEnabled,
            $workspaceEnabled ? $this->workspaces->resolve($subject) : null,
        );
    }
}
