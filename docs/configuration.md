# Configuration and schema reference

Publish configuration before the first migration whenever the application changes models, tables, pivot keys, or workspace column names:

```bash
php artisan vendor:publish --tag=kinship-config
```

## Models

```php
'models' => [
    'role' => Tetranyble\Kinship\Models\Role::class,
    'permission' => Tetranyble\Kinship\Models\Permission::class,
    'user' => null,
],
```

When `user` is `null`, Kinship resolves the active auth provider model. It must extend Eloquent `Model` and implement Laravel's `Authenticatable` contract.

Custom role and permission classes must extend Kinship's corresponding models so soft-delete behavior and authorization APIs remain intact.

## Tables and pivot keys

```php
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
```

These values configure all package relationships and migrations. The supplied role and permission primary keys and pivots use unsigned big integers. Applications with UUID/ULID user, role, or permission primary keys should publish and adapt the pivot migrations, then disable package migration loading.

Workspace identifiers are different: the roles table stores them in a string scope column and natively accepts integers, UUIDs, ULIDs, and strings.

## Guard

```php
'guard' => null,
```

`null` delegates to the host application. Kinship resolves a guard in this order:

1. A guard explicitly passed to an API such as `Role::forGuard('api')` or `kinship:seed --guard=api`.
2. The subject's public `guardName()` method.
3. The subject's non-empty `guard_name` attribute.
4. A non-null `kinship.guard` package fallback.
5. Laravel's runtime-selected guard from `AuthManager::getDefaultDriver()`.
6. `auth.defaults.guard`, with `web` only as the final framework-less fallback.

Laravel's `auth:<guard>` middleware calls `AuthManager::shouldUse()`, so normal authenticated requests automatically evaluate Kinship roles and permissions under that active guard. Set `kinship.guard` to a string only when the package needs an intentional fallback different from the application. A custom integration can bind `GuardResolver` before Kinship registers.

## Workspace

```php
'workspace' => [
    'enabled' => null,
    'resolver' => Tetranyble\Kinship\Workspace\ModelWorkspaceResolver::class,
    'context_resolver' => Tetranyble\Kinship\Workspace\ModelAuthorizationContextResolver::class,
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
```

| Key | Meaning |
| --- | --- |
| `enabled` | `null` contract/mapping detection, or an explicit boolean override |
| `resolver` | Strategy that resolves a workspace identifier from the current subject |
| `context_resolver` | Strategy producing the immutable guard/workspace authorization context |
| `subject_foreign_key` | Default workspace column on a user/subject |
| `role_foreign_key` | String scope column persisted on roles |
| `global_scope_value` | Reserved non-null scope for non-workspace authorization |
| `mapping.model` | Host Eloquent workspace model |
| `mapping.relationship` | Existing relationship name on an unmodified subject model |
| `mapping.subject_foreign_key` | Mapped subject workspace key |
| `mapping.workspace_owner_key` | Workspace model key read by relationship fallback |
| `mapping.role_foreign_key` | Optional mapped role scope column; defaults to top-level value |

The global sentinel must be a non-empty string that can never be a real workspace identifier.

## Acting roles

```php
'acting_roles' => [
    'enabled' => true,
    'allow_unassigned' => false,
    'session_prefix' => 'kinship.acting_role',
],
```

`allow_unassigned=false` is the secure default. Session storage is context-bound by user, guard, and workspace fingerprint.

## Permission catalog

Catalog seeding is an optional provisioning feature, independent from authorization at runtime:

```php
'catalog' => [
    'enabled' => false,
    'source' => Tetranyble\Kinship\Catalog\ConfigPermissionCatalog::class,
    'separator' => '.',
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
    ],
    'permissions' => [],
    'roles' => [
        'viewer' => ['permissions' => ['*.index', '*.view']],
    ],
],
```

With discovery disabled, nothing reads the host model directory. `resources` is only explicit configuration expanded as `<resource><separator><ability>`. Set `separator` to `:` for names such as `invoice:view`, select a per-resource ability subset with `['abilities' => ['view', 'update']]`, or set `resources=[]` to disable configured-resource expansion completely.

For compatibility with applications whose old permission bootstrapper scanned models, set `discovery.enabled=true`. The default `Models` path resolves to `app/Models`, and a null namespace derives `<application namespace>\Models`. Nested directories are supported. Only loadable, concrete Eloquent model classes become resources; abstract classes and non-model PHP classes are ignored. An absolute/custom path must provide its PSR-4 namespace explicitly:

```php
'discovery' => [
    'enabled' => true,
    'path' => base_path('src/Domain'),
    'namespace' => 'App\\Domain',
],
```

Discovery runs only when `kinship:seed` is explicitly invoked. Its resources are merged with `resources` and `permissions`, allowing gradual migration away from scanning.

`permissions` accepts all of these explicit forms:

```php
'permissions' => [
    'invoice:read',
    'invoice:approve' => 'Approve Invoice',
    ['name' => 'report.export', 'label' => 'Export Reports', 'group' => 'report'],
    'payment:refund' => ['label' => 'Refund Payment', 'group' => 'payments'],
],
```

Permission names are persisted verbatim. Labels and groups are inferred when omitted. Role permission entries are exact names or Laravel-style wildcard patterns, and only match permissions in the configured catalog.

Enable and run the command explicitly:

```bash
php artisan kinship:seed --dry-run
php artisan kinship:seed
php artisan kinship:seed --guard=api
php artisan kinship:seed --sync
```

| Option | Behavior |
| --- | --- |
| `--dry-run` | validates and counts the matrix without a database write |
| `--guard=api` | seeds definitions for a specific guard |
| `--sync` | replaces grants on catalog-managed roles; default behavior is additive |
| `--workspace=<id>` | seeds roles into one workspace scope |
| `--global` | explicitly selects the global scope in a workspace-enabled application |

Workspace-enabled applications must pass either `--workspace` or `--global`. Non-workspace applications reject `--workspace`. Re-running is safe: managed records are updated, soft-deleted managed records are restored, missing grants are attached, and unrelated records are never deleted. `--sync` may detach non-catalog grants from a catalog-managed role, so use it only when the catalog owns that role.

For a catalog stored in code, a database, or another service, implement `PermissionCatalog` and configure its class as `catalog.source`:

```php
use Tetranyble\Kinship\Catalog\PermissionDefinition;
use Tetranyble\Kinship\Catalog\RoleDefinition;
use Tetranyble\Kinship\Contracts\PermissionCatalog;

final class ApplicationPermissionCatalog implements PermissionCatalog
{
    public function permissions(): array
    {
        return [
            new PermissionDefinition('invoice:approve', 'Approve Invoice', 'invoice'),
        ];
    }

    public function roles(): array
    {
        return [
            new RoleDefinition(
                name: 'reviewer',
                label: 'Reviewer',
                description: 'Reviews invoices',
                order: 20,
                system: true,
                permissions: ['invoice:*'],
            ),
        ];
    }
}
```

The source is resolved through Laravel's container, so constructor injection is supported. An application can alternatively bind `PermissionCatalog` directly before Kinship registers.

## Permission cache and grant sources

```php
'cache' => [
    'enabled' => true,
    'store' => null,
    'prefix' => 'kinship',
    'ttl' => 3600,
],

'permission_sources' => [
    Tetranyble\Kinship\Permissions\DatabasePermissionGrantSource::class,
],
```

`store=null` follows Laravel's default cache store, including Redis when the host switches to it. `ttl` is expressed in seconds. Cache keys and invalidation versions are isolated by subject, guard, and workspace.

The built-in source merges direct and role permissions in one SQL statement. Append application-owned `PermissionGrantSource` implementations for teams or other grant mechanisms. Kinship intentionally provides the extension contract without owning team schema. See [Permission caching and grant sources](caching-and-sources.md) for query requirements, invalidation, and a complete team example.

## Middleware

```php
'middleware' => [
    'register_aliases' => true,
    'aliases' => [
        'kinship.role' => Tetranyble\Kinship\Http\Middleware\RoleMiddleware::class,
        'kinship.permission' => Tetranyble\Kinship\Http\Middleware\PermissionMiddleware::class,
    ],
    'unauthorized_message' => 'This action is unauthorized.',
],
```

Disable alias registration if the host application registers aliases centrally or needs different names.

## Migration ownership

```php
'migrations' => [
    'load' => true,
],
```

Kinship loads five migrations by default. To own/customize them:

```bash
php artisan vendor:publish --tag=kinship-migrations
```

Then set `migrations.load=false` before migrating. Do not load both copies.

## Schema

### Roles

- unsigned bigint `id`
- `name`, optional `label`, optional `description`
- integer `order`
- boolean `is_system`
- `guard_name`
- indexed, non-null string workspace scope column
- timestamps and `deleted_at`
- unique `(name, guard_name, workspace_scope_column)`

Global roles receive `global_scope_value` through both a database default and a model creating hook. This avoids database-specific `NULL` uniqueness semantics.

Workspace role IDs are stored canonically as strings. No foreign key is created to a host workspace table because the host may use any model, table, connection, or key type.

### Permissions

- unsigned bigint `id`
- `name`, optional `label`, optional indexed `group`
- `guard_name`
- timestamps and `deleted_at`
- unique `(name, guard_name)`

### Pivots

- `role_user`: user and role keys, timestamps, composite primary key
- `permission_user`: permission and user keys, timestamps, composite primary key
- `permission_role`: permission and role keys, timestamps, composite primary key

Package-owned role and permission references cascade on hard delete. Soft deletes retain pivots but related model global scopes prevent authorization; restoring the model restores its assignments.

## Existing schemas

For an existing authorization database:

1. Set `migrations.load=false`.
2. Configure model/table/pivot names.
3. Ensure role and permission models extend Kinship models.
4. Add the non-null role scope column and backfill global rows with the configured sentinel.
5. Replace role uniqueness with `(name, guard_name, scope)`.
6. Ensure `deleted_at` exists or intentionally override soft-delete behavior in application-owned models.
7. Run integration tests for role assignment, direct permissions, workspace switching, and guard switching.

## Stable workspace schema

Enabling or disabling workspace behavior does not add or remove columns. Existing global rows remain in the sentinel scope. New workspace rows use their canonical identifier.

When converting an existing global application to workspaces, decide whether old global roles should:

- remain global and be unavailable to workspace-aware users; or
- be duplicated/backfilled into specific workspaces through an application migration.

Kinship deliberately does not make that business decision automatically.

## Service container extension points

The provider binds defaults only if the application has not already bound them:

- `GuardResolver`
- `PermissionCacheStore`
- `PermissionNameResolver`
- `WorkspaceResolver`
- `AuthorizationContextResolver`
- `PermissionCatalog`

Configured resolver classes are resolved through the Laravel container and validated against their interfaces. `WorkspaceConfiguration` is registered as an immutable singleton after configuration merge.

## Validation failures

Kinship throws early for:

- non-boolean/non-null `workspace.enabled`;
- empty workspace keys or global sentinel;
- a partial workspace mapping;
- a mapped class that is not an Eloquent model;
- an invalid user, role, permission, workspace resolver, or context resolver class;
- a real workspace identifier equal to the global sentinel;
- a relationship returning an unexpected model.

## Supported versions and verification

Production constraints support PHP 8.2+ and Laravel 9–12. The GitHub Actions matrix tests:

- PHP 8.2 / Laravel 9 / Testbench 7 / PHPUnit 9;
- PHP 8.2 / Laravel 10 / Testbench 8 / PHPUnit 10;
- PHP 8.3 / Laravel 11 / Testbench 9 / PHPUnit 11;
- PHP 8.4 / Laravel 12 / Testbench 10 / PHPUnit 11.

Quality gates:

```bash
composer validate --strict
composer format
composer analyse
composer test
composer check
```

Laravel 9 compatibility is retained because Kinship consumers requested it, but Laravel 9 itself is upstream end-of-life. Composer may report framework advisories that cannot be fixed within the Laravel 9 line. Kinship does not use the affected email validation, file validation, or signed URL features, but host applications remain responsible for their overall framework risk and upgrade plan.
