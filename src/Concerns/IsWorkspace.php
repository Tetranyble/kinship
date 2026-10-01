<?php

namespace Tetranyble\Kinship\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Tetranyble\Kinship\Contracts\Group as GroupContract;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

trait IsWorkspace
{
    public function getWorkspaceIdentifier(): int|string
    {
        $configuration = app(WorkspaceConfiguration::class);
        $value = $this->getAttribute($configuration->workspaceOwnerKey());

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        $value = $this->getKey();

        if (! is_int($value) && ! is_string($value)) {
            throw new LogicException('A Kinship workspace must have a persisted identifier.');
        }

        return $value;
    }

    /** @return HasMany<Model&Authenticatable, $this> */
    public function kinshipUsers(): HasMany
    {
        $configuration = app(WorkspaceConfiguration::class);

        return $this->hasMany(
            KinshipModels::user(),
            $configuration->subjectForeignKey(),
            $configuration->workspaceOwnerKey(),
        );
    }

    /** @return HasMany<Role, $this> */
    public function kinshipRoles(): HasMany
    {
        $configuration = app(WorkspaceConfiguration::class);

        return $this->hasMany(
            KinshipModels::role(),
            $configuration->roleForeignKey(),
            $configuration->workspaceOwnerKey(),
        );
    }

    /** @return HasMany<Model&GroupContract, $this> */
    public function kinshipGroups(): HasMany
    {
        $configuration = app(WorkspaceConfiguration::class);

        return $this->hasMany(
            KinshipModels::group(),
            $configuration->groupForeignKey(),
            $configuration->workspaceOwnerKey(),
        );
    }
}
