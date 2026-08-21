<?php

namespace Tetranyble\Kinship;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Tetranyble\Kinship\Cache\LaravelPermissionCacheStore;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Cache\PermissionCacheKeys;
use Tetranyble\Kinship\Catalog\PermissionCatalogSeeder;
use Tetranyble\Kinship\Console\SeedPermissionCatalogCommand;
use Tetranyble\Kinship\Contracts\AuthorizationContextResolver;
use Tetranyble\Kinship\Contracts\GuardResolver;
use Tetranyble\Kinship\Contracts\PermissionCacheStore;
use Tetranyble\Kinship\Contracts\PermissionCatalog;
use Tetranyble\Kinship\Contracts\PermissionNameResolver;
use Tetranyble\Kinship\Contracts\WorkspaceResolver;
use Tetranyble\Kinship\Permissions\CachedPermissionNameResolver;
use Tetranyble\Kinship\Permissions\PermissionGrantQuery;
use Tetranyble\Kinship\Support\ApplicationGuardResolver;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

class KinshipServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/kinship.php', 'kinship');

        $this->app->singleton(
            WorkspaceConfiguration::class,
            fn (): WorkspaceConfiguration => WorkspaceConfiguration::fromConfig(),
        );

        if (! $this->app->bound(GuardResolver::class)) {
            $this->app->singleton(GuardResolver::class, ApplicationGuardResolver::class);
        }

        if (! $this->app->bound(PermissionCacheStore::class)) {
            $this->app->singleton(PermissionCacheStore::class, LaravelPermissionCacheStore::class);
        }

        if (! $this->app->bound(PermissionNameResolver::class)) {
            $this->app->singleton(PermissionNameResolver::class, CachedPermissionNameResolver::class);
        }

        $this->app->singleton(PermissionCacheKeys::class);
        $this->app->singleton(PermissionCacheInvalidator::class);
        $this->app->singleton(PermissionGrantQuery::class);

        if (! $this->app->bound(WorkspaceResolver::class)) {
            $this->app->bind(WorkspaceResolver::class, function ($app): WorkspaceResolver {
                $resolver = $app['config']->get('kinship.workspace.resolver');

                if (! is_string($resolver) || ! is_a($resolver, WorkspaceResolver::class, true)) {
                    throw new RuntimeException('The Kinship workspace resolver must implement '.WorkspaceResolver::class.'.');
                }

                return $app->make($resolver);
            });
        }

        if (! $this->app->bound(AuthorizationContextResolver::class)) {
            $this->app->bind(AuthorizationContextResolver::class, function ($app): AuthorizationContextResolver {
                $resolver = $app['config']->get('kinship.workspace.context_resolver');

                if (! is_string($resolver) || ! is_a($resolver, AuthorizationContextResolver::class, true)) {
                    throw new RuntimeException('The Kinship authorization context resolver must implement '.AuthorizationContextResolver::class.'.');
                }

                return $app->make($resolver);
            });
        }

        if (! $this->app->bound(PermissionCatalog::class)) {
            $this->app->bind(PermissionCatalog::class, function ($app): PermissionCatalog {
                $source = $app['config']->get('kinship.catalog.source');

                if (! is_string($source) || ! is_a($source, PermissionCatalog::class, true)) {
                    throw new RuntimeException('The Kinship permission catalog source must implement '.PermissionCatalog::class.'.');
                }

                return $app->make($source);
            });
        }

        $this->app->singleton(PermissionCatalogSeeder::class);
    }

    public function boot(Router $router): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SeedPermissionCatalogCommand::class]);
        }

        if ((bool) config('kinship.migrations.load', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ((bool) config('kinship.middleware.register_aliases', true)) {
            foreach ((array) config('kinship.middleware.aliases', []) as $alias => $middleware) {
                if (is_string($alias) && is_string($middleware)) {
                    $router->aliasMiddleware($alias, $middleware);
                }
            }
        }

        $this->publishes([
            __DIR__.'/../config/kinship.php' => config_path('kinship.php'),
        ], 'kinship-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'kinship-migrations');
    }
}
