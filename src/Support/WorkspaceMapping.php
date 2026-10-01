<?php

namespace Tetranyble\Kinship\Support;

use Illuminate\Database\Eloquent\Model;

final readonly class WorkspaceMapping
{
    /** @param class-string<Model> $model */
    public function __construct(
        public string $model,
        public ?string $relationship,
        public string $subjectForeignKey,
        public string $workspaceOwnerKey,
        public string $roleForeignKey,
        public string $groupForeignKey,
    ) {}

    /** @return array{model: class-string<Model>, relationship: ?string, subject_foreign_key: string, workspace_owner_key: string, role_foreign_key: string, group_foreign_key: string} */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'relationship' => $this->relationship,
            'subject_foreign_key' => $this->subjectForeignKey,
            'workspace_owner_key' => $this->workspaceOwnerKey,
            'role_foreign_key' => $this->roleForeignKey,
            'group_foreign_key' => $this->groupForeignKey,
        ];
    }
}
