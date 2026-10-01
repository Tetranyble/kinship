<?php

namespace Tetranyble\Kinship\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Tetranyble\Kinship\Contracts\WorkspaceSubject;

final readonly class WorkspaceConfiguration
{
    public function __construct(
        public ?bool $enabled,
        public ?WorkspaceMapping $mapping,
        public string $defaultSubjectForeignKey,
        public string $defaultRoleForeignKey,
        public string $defaultGroupForeignKey,
        public string $globalScopeValue,
    ) {}

    public static function fromConfig(): self
    {
        $enabled = config('kinship.workspace.enabled');

        if (! is_bool($enabled) && $enabled !== null) {
            throw new RuntimeException('Kinship workspace.enabled must be true, false, or null.');
        }

        $raw = (array) config('kinship.workspace.mapping', []);
        $defaultSubjectKey = self::requiredString(
            config('kinship.workspace.subject_foreign_key', 'workspace_id'),
            'workspace.subject_foreign_key',
        );
        $defaultRoleKey = self::requiredString(
            config('kinship.workspace.role_foreign_key', 'workspace_id'),
            'workspace.role_foreign_key',
        );
        $defaultGroupKey = self::requiredString(
            config('kinship.workspace.group_foreign_key', 'workspace_id'),
            'workspace.group_foreign_key',
        );
        $globalScope = self::requiredString(
            config('kinship.workspace.global_scope_value', '__kinship_global__'),
            'workspace.global_scope_value',
        );
        $mapping = self::mappingFrom($raw, $defaultSubjectKey, $defaultRoleKey, $defaultGroupKey);

        return new self($enabled, $mapping, $defaultSubjectKey, $defaultRoleKey, $defaultGroupKey, $globalScope);
    }

    public function enabledFor(?Model $subject = null): bool
    {
        $enabled = is_bool($this->enabled)
            ? $this->enabled
            : $subject instanceof WorkspaceSubject
                || $this->mapping !== null
                || $this->configuredUserUsesWorkspaces();

        if ($enabled) {
            KinshipModels::workspace();
        }

        return $enabled;
    }

    public function subjectForeignKey(): string
    {
        return $this->mapping !== null ? $this->mapping->subjectForeignKey : $this->defaultSubjectForeignKey;
    }

    public function roleForeignKey(): string
    {
        return $this->mapping !== null ? $this->mapping->roleForeignKey : $this->defaultRoleForeignKey;
    }

    public function groupForeignKey(): string
    {
        return $this->mapping !== null ? $this->mapping->groupForeignKey : $this->defaultGroupForeignKey;
    }

    public function workspaceOwnerKey(): string
    {
        if ($this->mapping !== null) {
            return $this->mapping->workspaceOwnerKey;
        }

        $workspace = KinshipModels::workspace();

        return (new $workspace)->getKeyName();
    }

    public function scopeValue(int|string|null $identifier, bool $workspaceEnabled): ?string
    {
        if (! $workspaceEnabled) {
            return $this->globalScopeValue;
        }

        if ($identifier === null || (is_string($identifier) && trim($identifier) === '')) {
            return null;
        }

        $value = (string) $identifier;

        if ($value === $this->globalScopeValue) {
            throw new RuntimeException('A workspace identifier cannot equal Kinship workspace.global_scope_value.');
        }

        return $value;
    }

    private function configuredUserUsesWorkspaces(): bool
    {
        $configured = config('kinship.models.user');

        if (! is_string($configured) || $configured === '') {
            $provider = (string) config('auth.defaults.provider', 'users');
            $configured = config("auth.providers.{$provider}.model")
                ?? config('auth.providers.users.model');
        }

        return is_string($configured) && is_a($configured, WorkspaceSubject::class, true);
    }

    /** @param array<string, mixed> $raw */
    private static function mappingFrom(
        array $raw,
        string $defaultSubjectKey,
        string $defaultRoleKey,
        string $defaultGroupKey,
    ): ?WorkspaceMapping {
        $relationship = self::optionalString($raw['relationship'] ?? null, 'workspace.mapping.relationship');
        $subjectKey = self::optionalString($raw['subject_foreign_key'] ?? null, 'workspace.mapping.subject_foreign_key');
        $ownerKey = self::optionalString($raw['workspace_owner_key'] ?? null, 'workspace.mapping.workspace_owner_key');
        $roleKey = self::optionalString($raw['role_foreign_key'] ?? null, 'workspace.mapping.role_foreign_key');
        $groupKey = self::optionalString($raw['group_foreign_key'] ?? null, 'workspace.mapping.group_foreign_key');

        if ($relationship === null && $subjectKey === null && $ownerKey === null && $roleKey === null && $groupKey === null) {
            return null;
        }

        $model = KinshipModels::workspace();
        $ownerKey ??= (new $model)->getKeyName();

        return new WorkspaceMapping(
            $model,
            $relationship,
            $subjectKey ?? $defaultSubjectKey,
            $ownerKey,
            $roleKey ?? $defaultRoleKey,
            $groupKey ?? $defaultGroupKey,
        );
    }

    private static function requiredString(mixed $value, string $key): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Kinship {$key} must be a non-empty string.");
        }

        return $value;
    }

    private static function optionalString(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException("Kinship {$key} must be null or a non-empty string.");
        }

        return $value;
    }
}
