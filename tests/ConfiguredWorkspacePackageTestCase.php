<?php

namespace Tetranyble\Kinship\Tests;

use Tetranyble\Kinship\Tests\Fixtures\MappedWorkspaceUser;
use Tetranyble\Kinship\Tests\Fixtures\Workspace;

abstract class ConfiguredWorkspacePackageTestCase extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', MappedWorkspaceUser::class);
        $app['config']->set('kinship.models.user', MappedWorkspaceUser::class);
        $app['config']->set('kinship.workspace.mapping', [
            'model' => Workspace::class,
            'relationship' => 'workspace',
            'subject_foreign_key' => 'organization_id',
            'workspace_owner_key' => 'id',
            'role_foreign_key' => 'organization_scope_id',
        ]);
    }
}
