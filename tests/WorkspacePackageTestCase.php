<?php

namespace Tetranyble\Kinship\Tests;

use Tetranyble\Kinship\Tests\Fixtures\WorkspaceUser;

abstract class WorkspacePackageTestCase extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', WorkspaceUser::class);
        $app['config']->set('kinship.models.user', WorkspaceUser::class);
    }
}
