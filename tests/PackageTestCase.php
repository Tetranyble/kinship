<?php

namespace Tetranyble\Kinship\Tests;

use Orchestra\Testbench\TestCase;
use Tetranyble\Kinship\KinshipServiceProvider;
use Tetranyble\Kinship\Tests\Fixtures\User;

abstract class PackageTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [KinshipServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('kinship.models.user', User::class);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
