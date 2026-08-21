<?php

use Tetranyble\Kinship\Catalog\ConfigPermissionCatalog;
use Tetranyble\Kinship\Http\Middleware\PermissionMiddleware;
use Tetranyble\Kinship\Http\Middleware\RoleMiddleware;
use Tetranyble\Kinship\Http\Middleware\ValidateImpersonationSession;
use Tetranyble\Kinship\Http\Middleware\ValidateStatelessImpersonation;
use Tetranyble\Kinship\Impersonation\GateImpersonationAuthorizer;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Permissions\DatabasePermissionGrantSource;
use Tetranyble\Kinship\Workspace\ModelAuthorizationContextResolver;
use Tetranyble\Kinship\Workspace\ModelWorkspaceResolver;

return [
    'models' => [
        'role' => Role::class,
        'permission' => Permission::class,
        'user' => null,
    ],

    'tables' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'role_user' => 'role_user',
        'permission_user' => 'permission_user',
        'permission_role' => 'permission_role',
    ],

    'columns' => [
        'role_foreign_key' => 'role_id',
        'permission_foreign_key' => 'permission_id',
        'user_foreign_key' => 'user_id',
    ],

    // null follows Laravel's runtime-active/default guard. Set a string only
    // when Kinship needs a package fallback different from the application.
    'guard' => null,

    'workspace' => [
        // null auto-detects the WorkspaceSubject contract or a complete mapping.
        // Set false to force single-workspace mode or true for a custom resolver.
        'enabled' => null,
        'resolver' => ModelWorkspaceResolver::class,
        'context_resolver' => ModelAuthorizationContextResolver::class,
        'subject_foreign_key' => 'workspace_id',
        'role_foreign_key' => 'workspace_id',
        'global_scope_value' => '__kinship_global__',
        'mapping' => [
            'model' => null,
            'relationship' => null,
            'subject_foreign_key' => null,
            'workspace_owner_key' => 'id',
            'role_foreign_key' => null,
        ],
    ],

    'acting_roles' => [
        'enabled' => true,
        // replace evaluates only the assumed role; merge adds it to normal grants.
        'mode' => 'replace',
        'allow_unassigned' => false,
        'session_prefix' => 'kinship.acting_role',
    ],

    'impersonation' => [
        // User impersonation is independent of acting/assumed roles and must
        // be deliberately enabled by the host application.
        'enabled' => false,
        'authorizer' => GateImpersonationAuthorizer::class,
        'ability' => 'kinship.impersonate',
        'require_reason' => true,
        'max_reason_length' => 1000,
        'require_same_user_type' => true,
        'ttl' => 1800,
        'session_key' => 'kinship.impersonation',
        // Create a new subclass for application-owned MFA/account checks;
        // existing application middleware does not need to be modified.
        'middleware' => ValidateImpersonationSession::class,
        // Configure an application token broker to opt into stateless support.
        'stateless_broker' => null,
        // May be replaced by a subclass for application-owned conditions.
        'stateless_middleware' => ValidateStatelessImpersonation::class,
        // Explicit route/group placement is the secure default. Set true only
        // when the host intentionally wants Kinship on an entire group.
        'auto_middleware' => false,
        'middleware_group' => 'web',
    ],

    'cache' => [
        // Uses Laravel's configured cache store when null. Switching the host
        // application to Redis requires no Kinship-specific integration.
        'enabled' => true,
        'store' => null,
        'prefix' => 'kinship',
        'ttl' => 3600,
    ],

    // Applications can append PermissionGrantSource implementations for
    // teams, groups, subscriptions, or another domain-owned grant source.
    'permission_sources' => [
        DatabasePermissionGrantSource::class,
    ],

    'catalog' => [
        // Catalog seeding never runs automatically. Enable it explicitly and
        // execute `php artisan kinship:seed` for the desired scope.
        'enabled' => false,
        'source' => ConfigPermissionCatalog::class,
        'separator' => '.',

        // Compatibility adapter for applications that derive permissions from
        // Eloquent model class names. Scanning occurs only during kinship:seed.
        'discovery' => [
            'enabled' => false,
            'path' => 'Models',
            'namespace' => null,
        ],

        'abilities' => [
            'index' => 'View All',
            'view' => 'Read',
            'create' => 'Create',
            'update' => 'Update',
            'delete' => 'Delete',
            'restore' => 'Restore',
            'force_delete' => 'Permanently Delete',
        ],

        'resources' => [
            'user' => ['label' => 'User'],
            'role' => ['label' => 'Role'],
            'permission' => ['label' => 'Permission'],
        ],

        'permissions' => [],

        'roles' => [
            'viewer' => [
                'label' => 'Viewer',
                'description' => 'Read-only access to catalog resources',
                'order' => 40,
                'is_system' => true,
                'permissions' => ['*.index', '*.view'],
            ],
            'contributor' => [
                'label' => 'Contributor',
                'description' => 'Read, create, and update catalog resources',
                'order' => 30,
                'is_system' => true,
                'permissions' => ['*.index', '*.view', '*.create', '*.update'],
            ],
            'manager' => [
                'label' => 'Manager',
                'description' => 'Manage catalog resources except permanent deletion',
                'order' => 20,
                'is_system' => true,
                'permissions' => ['*.index', '*.view', '*.create', '*.update', '*.delete', '*.restore'],
            ],
            'owner' => [
                'label' => 'Owner',
                'description' => 'Full access to every catalog permission',
                'order' => 10,
                'is_system' => true,
                'permissions' => ['*'],
            ],
        ],
    ],

    'middleware' => [
        'register_aliases' => true,
        'aliases' => [
            'kinship.role' => RoleMiddleware::class,
            'kinship.permission' => PermissionMiddleware::class,
            'kinship.impersonation.valid' => ValidateImpersonationSession::class,
        ],
        'unauthorized_message' => 'This action is unauthorized.',
    ],

    // Existing applications can disable this while their original migration
    // files remain in the host application's migration directory.
    'migrations' => [
        'load' => true,
    ],
];
