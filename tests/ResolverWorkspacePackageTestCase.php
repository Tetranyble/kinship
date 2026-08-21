<?php

namespace Tetranyble\Kinship\Tests;

use Tetranyble\Kinship\Tests\Fixtures\FixedWorkspaceResolver;

abstract class ResolverWorkspacePackageTestCase extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinship.workspace.enabled', true);
        $app['config']->set('kinship.workspace.resolver', FixedWorkspaceResolver::class);
    }
}
