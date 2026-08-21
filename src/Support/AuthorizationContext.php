<?php

namespace Tetranyble\Kinship\Support;

final readonly class AuthorizationContext
{
    public function __construct(
        public string $guard,
        public bool $workspaceEnabled,
        public int|string|null $workspaceIdentifier,
    ) {}

    public function workspaceScope(WorkspaceConfiguration $configuration): ?string
    {
        return $configuration->scopeValue($this->workspaceIdentifier, $this->workspaceEnabled);
    }

    public function canAuthorize(WorkspaceConfiguration $configuration): bool
    {
        return $this->workspaceScope($configuration) !== null;
    }

    public function fingerprint(WorkspaceConfiguration $configuration): string
    {
        $scope = $this->workspaceScope($configuration) ?? '__kinship_unresolved__';

        return hash('sha256', $this->guard."\0".($this->workspaceEnabled ? 'workspace' : 'global')."\0".$scope);
    }
}
