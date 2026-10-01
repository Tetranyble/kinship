# Configuration and schema

## Core configuration

Kinship owns role/permission authorization only. It does not derive authorization from Laravel guard names. Authentication must establish the subject before Kinship middleware runs.

```php
'models' => [
    'role' => Role::class,
    'permission' => Permission::class,
    'group' => Group::class,
    'workspace' => App\Models\Workspace::class,
    'user' => null,
],

'tables' => [
    'roles' => 'roles',
    'permissions' => 'permissions',
    'role_user' => 'role_user',
    'permission_user' => 'permission_user',
    'permission_role' => 'permission_role',
    'groups' => 'groups',
    'group_user' => 'group_user',
    'group_role' => 'group_role',
    'group_permission' => 'group_permission',
],

'columns' => [
    'role_foreign_key' => 'role_id',
    'permission_foreign_key' => 'permission_id',
    'user_foreign_key' => 'user_id',
    'group_foreign_key' => 'group_id',
],
```

The supplied migrations use unsigned big integer subject/role/permission keys. Applications using UUID/ULID keys should publish and adapt the migrations, then set `migrations.load=false`. Workspace identifiers are stored canonically as strings and may be integer, UUID, ULID, or string values.

## Workspace

```php
'workspace' => [
    'enabled' => null,
    'resolver' => Tetranyble\Kinship\Workspace\ModelWorkspaceResolver::class,
    'context_resolver' => Tetranyble\Kinship\Workspace\ModelAuthorizationContextResolver::class,
    'subject_foreign_key' => 'workspace_id',
    'role_foreign_key' => 'workspace_id',
    'group_foreign_key' => 'workspace_id',
    'global_scope_value' => '__kinship_global__',
    'mapping' => [
        'relationship' => null,
        'subject_foreign_key' => null,
        'workspace_owner_key' => null,
        'role_foreign_key' => null,
        'group_foreign_key' => null,
    ],
],
```

`models.workspace` is the single configured host tenant model. It must be an Eloquent model implementing `Tetranyble\Kinship\Contracts\Workspace`; the optional `IsWorkspace` trait supplies the conventional identifier and inverse relationships. `role_foreign_key` and `group_foreign_key` are intentionally separate so an existing role table and an existing team/access-group table do not have to share a physical tenant column name. `enabled=null` auto-detects `WorkspaceSubject`, a mapping override, or a configured user model implementing the subject contract. A workspace-aware subject without an identifier fails closed.


## Host-owned group model

The default `Tetranyble\Kinship\Models\Group` is optional. A host application may configure any Eloquent model implementing `Tetranyble\Kinship\Contracts\Group`:

```php
'models' => [
    'group' => App\Models\Team::class,
],

'group' => [
    'lookup_columns' => ['code'],
],
```

`Tetranyble\Kinship\Concerns\IsGroup` supplies the conventional relationships, mutation helpers, scope immutability, and cache invalidation. The contract requires only a persisted group identifier and workspace identifier. Group `name`, `label`, `description`, `is_system`, soft deletes, timestamps, and BIGINT identifiers are defaults of the package-owned schema, not package-wide requirements.

When using an existing group table or UUID/ULID identifiers, the application should own/adapt the migrations and set `kinship.migrations.load=false` so the package does not also create its conventional schema.

## Cache

```php
'cache' => [
    'enabled' => true,
    'store' => null,
    'prefix' => 'kinship',
    'ttl' => 3600,
],
```

Cache entries are isolated by subject and workspace. Definition, workspace-scope, and subject-scope version keys invalidate flattened grants without key scans.

## Permission catalog

The catalog is disabled by default and never runs automatically. Enable it explicitly, then use:

```bash
php artisan kinship:seed --dry-run
php artisan kinship:seed
php artisan kinship:seed --workspace=<id>
php artisan kinship:seed --global
php artisan kinship:seed --sync
```

There is no guard option because authentication strategy is not part of authorization identity.

## Schema

### roles

- unsigned bigint `id`
- `name`, optional `label`, optional `description`
- integer `order`
- boolean `is_system`
- indexed non-null workspace scope column
- timestamps and `deleted_at`
- unique `(name, workspace_scope_column)`

### permissions

- unsigned bigint `id`
- unique `name`
- optional `label`, optional indexed `group`
- timestamps and `deleted_at`

### role_user

- subject key + role key
- composite primary key
- timestamps

### permission_role

- permission key + role key
- composite primary key
- timestamps

### permission_user

- permission key + subject key + workspace scope
- composite primary key over all three columns
- subject/scope index
- timestamps

### groups (default package migration)

- unsigned bigint `id`
- `name`, optional `label`, optional `description`
- boolean `is_system` metadata
- indexed non-null configured group workspace scope column
- timestamps and `deleted_at`
- unique `(name, workspace_scope_column)`

These are conventional defaults for `Tetranyble\Kinship\Models\Group`. Host-owned models may use different fields, table names, primary-key types, deletion behavior, and workspace column names as long as their contract/configuration supplies the required authorization semantics.

### group_user

- group key + subject key
- composite primary key
- reverse lookup index
- timestamps

The membership pivot deliberately has no workspace column: the tenant is defined by the group. One identity may therefore have memberships in groups belonging to several tenants, while authorization activates only the current workspace.

### group_role / group_permission

- group + role or group + permission composite primary key
- timestamps
- role/group workspace equality is enforced by Kinship mutations, and authorization queries independently require both principals to match the current workspace

The workspace column on `permission_user` is intentional: direct grants are workspace-local instead of silently applying to every workspace belonging to the same subject.

## Existing installations

The 2.x upgrade migration removes authorization `guard_name` columns and adds workspace scope to direct permission pivots. Review direct grants before deployment: legacy direct grants had no workspace boundary, so the migration places them in Kinship's global sentinel rather than guessing a tenant. Applications that want old grants in particular workspaces should perform an explicit domain migration.

## Extension points

Kinship exposes `PermissionCacheStore`, `PermissionNameResolver`, `EffectiveRoleResolver`, `WorkspaceResolver`, `AuthorizationContextResolver`, `PermissionCatalog`, and `PermissionGrantSource`. Authentication guard resolution is deliberately not an extension point because it is outside RBAC semantics.
