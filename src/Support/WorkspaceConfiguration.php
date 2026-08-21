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
        $globalScope = self::requiredString(
            config('kinship.workspace.global_scope_value', '__kinship_global__'),
            'workspace.global_scope_value',
        );
        $mapping = self::mappingFrom($raw, $defaultRoleKey);

        return new self($enabled, $mapping, $defaultSubjectKey, $defaultRoleKey, $globalScope);
    }

    public function enabledFor(?Model $subject = null): bool
    {
        if (is_bool($this->enabled)) {
            return $this->enabled;
        }

        if ($subject instanceof WorkspaceSubject) {
            return true;
        }

        return $this->mapping !== null || $this->configuredUserUsesWorkspaces();
    }

    public function subjectForeignKey(): string
    {
        return $this->mapping === null
            ? $this->defaultSubjectForeignKey
            : $this->mapping->subjectForeignKey;
    }

    public function roleForeignKey(): string
    {
        return $this->mapping === null
            ? $this->defaultRoleForeignKey
            : $this->mapping->roleForeignKey;
    }

    /**
     * Convert an application identifier into Kinship's stable storage format.
     * A null return value means a workspace-aware subject has no usable context
     * and authorization must fail closed.
     */
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
    private static function mappingFrom(array $raw, string $defaultRoleKey): ?WorkspaceMapping
    {
        $intent = array_filter([
            $raw['model'] ?? null,
            $raw['relationship'] ?? null,
            $raw['subject_foreign_key'] ?? null,
        ], fn (mixed $value): bool => is_string($value) && $value !== '');

        if ($intent === []) {
            return null;
        }

        $values = [
            'model' => $raw['model'] ?? null,
            'relationship' => $raw['relationship'] ?? null,
            'subject_foreign_key' => $raw['subject_foreign_key'] ?? null,
            'workspace_owner_key' => $raw['workspace_owner_key'] ?? 'id',
            'role_foreign_key' => $raw['role_foreign_key'] ?? $defaultRoleKey,
        ];

        foreach ($values as $key => $value) {
            if (! is_string($value) || $value === '') {
                throw new RuntimeException("Kinship workspace mapping requires [{$key}].");
            }
        }

        if (! is_a($values['model'], Model::class, true)) {
            throw new RuntimeException('The Kinship workspace mapping model must extend '.Model::class.'.');
        }

        /** @var class-string<Model> $model */
        $model = $values['model'];

        return new WorkspaceMapping(
            $model,
            $values['relationship'],
            $values['subject_foreign_key'],
            $values['workspace_owner_key'],
            $values['role_foreign_key'],
        );
    }

    private static function requiredString(mixed $value, string $key): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Kinship {$key} must be a non-empty string.");
        }

        return $value;
    }
}
