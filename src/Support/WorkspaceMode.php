<?php

namespace Tetranyble\Kinship\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Backward-compatible facade. New integrations should inject
 * WorkspaceConfiguration instead of depending on this static API.
 */
final class WorkspaceMode
{
    public static function enabled(?Model $subject = null): bool
    {
        return self::configuration()->enabledFor($subject);
    }

    public static function hasMapping(): bool
    {
        return self::configuration()->mapping !== null;
    }

    /** @return array{model: mixed, relationship: mixed, subject_foreign_key: mixed, workspace_owner_key: mixed, role_foreign_key: mixed} */
    public static function mapping(): array
    {
        $configuration = self::configuration();

        return $configuration->mapping?->toArray() ?? [
            'model' => null,
            'relationship' => null,
            'subject_foreign_key' => $configuration->defaultSubjectForeignKey,
            'workspace_owner_key' => 'id',
            'role_foreign_key' => $configuration->defaultRoleForeignKey,
        ];
    }

    public static function subjectForeignKey(): string
    {
        return self::configuration()->subjectForeignKey();
    }

    public static function roleForeignKey(): string
    {
        return self::configuration()->roleForeignKey();
    }

    private static function configuration(): WorkspaceConfiguration
    {
        return WorkspaceConfiguration::fromConfig();
    }
}
