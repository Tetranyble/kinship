<?php

namespace Tetranyble\Kinship;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Tetranyble\Kinship\Cache\LaravelPermissionCacheStore;
use Tetranyble\Kinship\Cache\PermissionBackedRoleCacheStore;
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Cache\PermissionCacheKeys;
use Tetranyble\Kinship\Catalog\PermissionCatalogSeeder;
use Tetranyble\Kinship\Console\SeedPermissionCatalogCommand;
use Tetranyble\Kinship\Contracts\AuthorizationContextResolver;
use Tetranyble\Kinship\Contracts\EffectiveRoleResolver;
use Tetranyble\Kinship\Contracts\ImpersonationAuthorizer;
use Tetranyble\Kinship\Contracts\PermissionCacheStore;
use Tetranyble\Kinship\Contracts\PermissionCatalog;
use Tetranyble\Kinship\Contracts\PermissionNameResolver;
use Tetranyble\Kinship\Contracts\RoleCacheStore;
use Tetranyble\Kinship\Contracts\StatelessImpersonationTokenBroker;
use Tetranyble\Kinship\Contracts\WorkspaceResolver;
use Tetranyble\Kinship\Http\Middleware\ValidateImpersonationSession;
use Tetranyble\Kinship\Http\Middleware\ValidateStatelessImpersonation;
use Tetranyble\Kinship\Impersonation\ImpersonationManager;
use Tetranyble\Kinship\Permissions\CachedPermissionNameResolver;
use Tetranyble\Kinship\Permissions\PermissionGrantQuery;
use Tetranyble\Kinship\Roles\CachedEffectiveRoleResolver;
use Tetranyble\Kinship\Roles\RoleGrantQuery;
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

        if (! $this->app->bound(PermissionCacheStore::class)) {
            $this->app->singleton(PermissionCacheStore::class, LaravelPermissionCacheStore::class);
        }

        if (! $this->app->bound(RoleCacheStore::class)) {
            $this->app->singleton(RoleCacheStore::class, PermissionBackedRoleCacheStore::class);
        }

        if (! $this->app->bound(ImpersonationAuthorizer::class)) {
            $this->app->bind(ImpersonationAuthorizer::class, function ($app): ImpersonationAuthorizer {
                $authorizer = $app['config']->get('kinship.impersonation.authorizer');

                if (! is_string($authorizer) || ! is_a($authorizer, ImpersonationAuthorizer::class, true)) {
                    throw new RuntimeException('The Kinship impersonation authorizer must implement '.ImpersonationAuthorizer::class.'.');
                }

                return $app->make($authorizer);
            });
        }

        $this->registerStatelessBroker();

        if (! $this->app->bound(PermissionNameResolver::class)) {
            $this->app->singleton(PermissionNameResolver::class, CachedPermissionNameResolver::class);
        }

        if (! $this->app->bound(EffectiveRoleResolver::class)) {
            $this->app->singleton(EffectiveRoleResolver::class, CachedEffectiveRoleResolver::class);
        }

        $this->app->singleton(PermissionCacheKeys::class);
        $this->app->singleton(PermissionCacheInvalidator::class);
        $this->app->singleton(PermissionGrantQuery::class);
        $this->app->singleton(RoleGrantQuery::class);
        $this->app->singleton(ImpersonationManager::class);

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
        // Testbench and advanced hosts may finalize package configuration after
        // provider registration but before booting.
        $this->registerStatelessBroker();

        if ($this->app->runningInConsole()) {
            $this->commands([SeedPermissionCatalogCommand::class]);
        }

        if ((bool) config('kinship.migrations.load', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $impersonationMiddleware = $this->impersonationMiddleware();

        if ((bool) config('kinship.middleware.register_aliases', true)) {
            $aliases = (array) config('kinship.middleware.aliases', []);
            $aliases['kinship.impersonation.valid'] = $impersonationMiddleware;
            $statelessMiddleware = $this->statelessImpersonationMiddleware();

            if ($statelessMiddleware !== null) {
                $aliases['kinship.impersonation.stateless'] = $statelessMiddleware;
            }

            foreach ($aliases as $alias => $middleware) {
                if (is_string($alias) && is_string($middleware)) {
                    $router->aliasMiddleware($alias, $middleware);
                }
            }
        }

        if ((bool) config('kinship.impersonation.auto_middleware', false)) {
            $group = config('kinship.impersonation.middleware_group', 'web');
            if (! is_string($group) || trim($group) === '') {
                throw new RuntimeException('The Kinship impersonation middleware group must be a non-empty string.');
            }

            $router->pushMiddlewareToGroup($group, $impersonationMiddleware);
        }

        $this->publishes([
            __DIR__.'/../config/kinship.php' => config_path('kinship.php'),
        ], 'kinship-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'kinship-migrations');
    }

    /** @return class-string<ValidateImpersonationSession> */
    private function impersonationMiddleware(): string
    {
        $middleware = config('kinship.impersonation.middleware', ValidateImpersonationSession::class);

        if (! is_string($middleware) || ! is_a($middleware, ValidateImpersonationSession::class, true)) {
            throw new RuntimeException('The Kinship impersonation middleware must extend '.ValidateImpersonationSession::class.'.');
        }

        return $middleware;
    }

    /** @return class-string<ValidateStatelessImpersonation>|null */
    private function statelessImpersonationMiddleware(): ?string
    {
        if (! $this->app->bound(StatelessImpersonationTokenBroker::class)) {
            return null;
        }

        $middleware = config(
            'kinship.impersonation.stateless_middleware',
            ValidateStatelessImpersonation::class,
        );

        if (! is_string($middleware) || ! is_a($middleware, ValidateStatelessImpersonation::class, true)) {
            throw new RuntimeException('The Kinship stateless impersonation middleware must extend '.ValidateStatelessImpersonation::class.'.');
        }

        return $middleware;
    }

    private function registerStatelessBroker(): void
    {
        $statelessBroker = config('kinship.impersonation.stateless_broker');
        if ($statelessBroker === null || $this->app->bound(StatelessImpersonationTokenBroker::class)) {
            return;
        }

        if (! is_string($statelessBroker)
            || ! is_a($statelessBroker, StatelessImpersonationTokenBroker::class, true)) {
            throw new RuntimeException('The Kinship stateless impersonation broker must implement '.StatelessImpersonationTokenBroker::class.'.');
        }

        $this->app->singleton(StatelessImpersonationTokenBroker::class, $statelessBroker);
    }
}
