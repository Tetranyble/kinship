# Upgrading to the guard-independent authorization model

Kinship 2.x removes Laravel authentication guards from RBAC identity. Authentication still establishes the request principal, but roles and permissions are now resolved only from the subject and workspace scope.

## Before migrating

1. Audit legacy `roles.guard_name` and `permissions.guard_name` values.
2. If more than one distinct authorization guard exists, consolidate those definitions and assignments deliberately. The upgrade migration refuses to merge multiple legacy guard realms automatically.
3. Audit legacy direct user permissions. They previously had no workspace scope. The upgrade migration places them in Kinship's global sentinel so workspace-aware subjects do not accidentally inherit them.
4. If a legacy direct grant belongs in a tenant/workspace, write an application migration that copies or moves it to that explicit scope.

## API removals

The following authorization APIs/configuration are removed:

- `GuardResolver`
- `ApplicationGuardResolver`
- `kinship.guard`
- `Role::forGuard()`
- `Permission::forGuard()`
- `kinship:seed --guard=...`
- `guard_name` on roles and permissions

Impersonation still records the Laravel guard it actually switched because restoring a stateful authenticated identity is an authentication concern, not an RBAC boundary.

## Direct permission behavior

`permission_user` now includes the configured workspace scope column. Kinship's relation uses `withPivotValue()`, so direct Eloquent attaches automatically persist the current scope. A direct grant in workspace A does not authorize workspace B.

## Cache behavior

Kinship uses shared versioned caching only. Per-model permission caches were removed to prevent same-request stale allows after revocation. Package pivot models invalidate affected scopes for Eloquent `attach`, `detach`, and `sync` operations. Raw SQL remains outside Eloquent event guarantees and must be paired with explicit cache invalidation.


## Workspace and Group host models

Workspace configuration now has one model source of truth:

```php
'models' => [
    'workspace' => App\Models\Workspace::class,
    'group' => App\Models\Team::class,
],
```

`workspace.mapping.model` is no longer part of the integration contract. `App\Models\Workspace` must implement `Contracts\Workspace`; `IsWorkspace` is the conventional trait. A custom group model must implement `Contracts\Group`; `IsGroup` supplies the standard relationships and mutation behavior.

Applications with an existing group/team table may configure a group workspace column separately from the role workspace column:

```php
'workspace' => [
    'role_foreign_key' => 'workspace_id',
    'group_foreign_key' => 'tenant_id',
],
```

The package-owned migrations remain conventional BIGINT schemas. Existing UUID/ULID/custom tables should be owned by the host application: publish/adapt the migrations and disable package migration loading after ownership is transferred.
