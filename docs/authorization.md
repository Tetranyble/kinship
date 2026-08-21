# Authorization guide

Kinship implements role-based access control with two mandatory boundaries: guard and authorization scope. The scope is either Kinship's global sentinel or a resolved workspace identifier.

## Models

### Role

```php
$role = Role::create([
    'name' => 'reviewer',
    'label' => 'Reviewer',
    'description' => 'Reviews submitted applications',
    'order' => 20,
    'is_system' => false,
    'guard_name' => 'web',
    'workspace_id' => '01J5ZX4M3T8JH9YB2X6QK7P4NW', // omit in a global app
]);
```

Role names are unique for a guard and stored scope. The scope column is a non-null string, so global role uniqueness is enforced consistently by the database.

`Role` uses Laravel `SoftDeletes`. Deleted roles are automatically excluded from relationships, assignment resolution, acting-role lookup, and authorization.

### Permission

```php
$permission = Permission::create([
    'name' => 'application.review',
    'label' => 'Review applications',
    'group' => 'application',
    'guard_name' => 'web',
]);
```

Permissions are global definitions unique by name and guard. `Permission` also uses `SoftDeletes`, so deleted permissions cannot contribute to effective authorization.

Permission names are application vocabulary, not required model metadata. `application.review`, `invoice:approve`, and `export-financial-report` are equally valid. The optional [permission catalog](configuration.md#permission-catalog) can provision explicit names and starter roles through `php artisan kinship:seed`; model discovery is available only as an explicitly enabled compatibility adapter.

## User concern

Add `HasRolesAndPermissions` to an Eloquent authenticatable model. The configured user model is validated as both an Eloquent model and an `Authenticatable` implementation.

### Relationships

```php
$user->roles();           // current guard/workspace only
$user->allRoles();        // explicitly unscoped persistence relation
$user->userPermissions(); // direct global permission grants
```

The safe default is `roles()`. Administrative screens that intentionally aggregate assignments across workspaces may use `allRoles()`, but must display the scope and must not authorize from that collection.

## Role assignment

All role inputs are resolved inside the current guard and workspace context. Models, IDs, names, labels, arrays, and collections are accepted.

```php
$user->assignRoles('reviewer');            // additive
$user->assignRoles($reviewer, $manager);
$user->syncRoles('reviewer');              // replace current-context roles
$user->removeRoles('reviewer');
```

`syncRoles()` preserves assignments belonging to other workspaces and guards. This matters when one persisted user has memberships in several workspaces.

An unresolved workspace context produces no role IDs and therefore cannot attach, remove, or authorize workspace roles.

## Permission assignment

Role permissions:

```php
$role->givePermissionTo('application.review'); // additive
$role->syncPermissions('application.review');
$role->revokePermissionTo('application.review');
```

Direct user permissions:

```php
$user->assignPermissions('profile.update');
$user->syncPermissions('profile.update');
$user->removePermissions('profile.update');
```

Permission-to-role inverse operations have explicit additive and replacement names:

```php
$permission->assignRoles($reviewer);       // additive
$permission->syncRoles($reviewer);         // replacement
```

Both operations reject roles from a different guard. Because permission definitions are global, compatible roles may belong to different workspaces.

## Checks

```php
$user->hasRole('reviewer');
$user->hasRoles(['reviewer', 'manager']);       // any-of compatibility alias
$user->hasAnyRole('reviewer', 'manager');
$user->hasAllRoles('reviewer', 'manager');

$user->hasPermission('application.review');
$user->hasPermissions(['application.review']); // any-of compatibility alias
$user->hasAnyPermission('application.review', 'application.approve');
$user->hasAllPermissions('application.review', 'application.approve');
```

Empty any/all input returns `false`. Model inputs are validated against the current context; passing a role object from another workspace does not bypass scoping.

## Effective permission calculation

Without an assumed role, `allPermissions()` combines:

1. Permissions on roles assigned within the current guard/workspace.
2. Direct user permissions for the current guard.

When a role is assumed, `replace` mode evaluates only that role. The opt-in
`merge` mode adds its permissions to the normal effective set.

The result is guard-filtered and deduplicated by model type and key.

The cache is indexed by a SHA-256 fingerprint of guard, mode, and canonical workspace scope. Switching guard or workspace on the same user object computes a separate effective set. Package mutations clear all local authorization cache entries.

Across requests, Kinship caches flattened permission names through Laravel's configured cache store. The shared key additionally includes version tokens for the guard, workspace scope, and subject. Package mutation methods rotate the appropriate version without enumerating affected users. See [Permission caching and grant sources](caching-and-sources.md).

When another service changes pivots behind an already-loaded instance, explicitly invalidate it:

```php
$user->forgetKinshipAuthorizationCache();
```

## Acting roles

Acting roles let the same authenticated user temporarily evaluate authorization
under a selected role. This is role assumption, not user impersonation.

```php
if ($user->actAs('reviewer')) {
    // selected successfully
}

$user->getActingRole();
$user->isActingAs();
$user->stopActingAs();

// Equivalent, intention-revealing aliases:
$user->assumeRole('reviewer');
$user->assumedRole();
$user->isAssumingRole();
$user->stopAssumingRole();
```

The session key includes the user model class, user key, guard, and workspace fingerprint. An acting role cached in workspace A is never returned in workspace B or under another guard.

By default, the user must already have the role:

```php
'acting_roles' => [
    'enabled' => true,
    'mode' => 'replace',
    'allow_unassigned' => false,
    'session_prefix' => 'kinship.acting_role',
],
```

`replace` is the safe default: normal roles, direct permissions, and custom grant
sources are suspended while the role is assumed. Set `mode` to `merge` only when
the application intentionally wants the assumed role added to normal access.

Set `allow_unassigned` only for a deliberately authorized preview workflow. Kinship still restricts the selected role to the current guard and workspace.

## Guard resolution

Kinship resolves a subject guard in this order:

1. A public `guardName()` method on the user model.
2. A populated Eloquent `guard_name` attribute.
3. Non-null `kinship.guard` configuration.
4. Laravel's runtime-active guard.
5. Laravel's configured default guard.

With `kinship.guard=null`, an `auth:api` request therefore uses `api`, while an ordinary default-guard request uses the application's `auth.defaults.guard`. Laravel selects the runtime guard through its authentication middleware; Kinship reads that selection rather than assuming `web`.

`guard_name` is persisted on both roles and permissions. When either Kinship model is created through Eloquent without an explicit `guard_name`, it stores the resolved active guard. Explicit values remain unchanged. The schema default uses the application guard resolved during migration as a fallback for query-builder or bulk inserts, which bypass Eloquent events; bulk application code should pass `guard_name` explicitly whenever it targets a non-default guard.

Role and permission definitions from other guards are rejected during assignment and checks.

## Middleware

```php
Route::get('/review', ReviewController::class)
    ->middleware(['auth:web', 'kinship.role:reviewer,manager']);

Route::post('/approve', ApproveController::class)
    ->middleware(['auth:web', 'kinship.permission:application.approve']);

Route::get('/api/reports', ReportController::class)
    ->middleware(['auth:api', 'kinship.permission:report.view']);
```

Laravel authentication middleware must run before Kinship so it can authenticate the subject and select the active guard. `auth:api` selects permission and role rows with `guard_name=api`; do not store the literal middleware expression `auth:api` in `guard_name`. When provisioning this guard, use `php artisan kinship:seed --guard=api`.

Kinship arguments use any-of semantics. Middleware first checks the request's resolved user, then considers route `auth:` guards and configured guards. HTML requests abort with 403; JSON requests return:

```json
{
    "status": false,
    "message": "This action is unauthorized."
}
```

## Policies

Extend `BasePolicy` to derive `snake_case_model.ability` permission names:

```php
final class LoanApplicationPolicy extends BasePolicy
{
    public function update(User $user, LoanApplication $application): bool
    {
        return $this->allowsAbility($user, 'update', $application);
    }
}
```

This checks `loan_application.update`. Override `$permissionPrefix` when the domain permission vocabulary differs from the model name.

## Failure behavior

Kinship deliberately fails closed when:

- workspace mode is active but no identifier resolves;
- a role belongs to another guard or workspace;
- a workspace identifier is empty or collides with the global sentinel;
- an acting role no longer exists or was soft deleted;
- a mapping is incomplete or returns the wrong model type;
- configured model or resolver classes violate their contracts.
