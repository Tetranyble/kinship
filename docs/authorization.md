# Authorization

Kinship authorization is intentionally independent of Laravel authentication guards. Authentication answers who the subject is; Kinship answers what that subject may do in the current workspace. A session-authenticated user, Sanctum token user, Passport user, or another authenticated transport therefore receives the same RBAC grants for the same subject and workspace.

## Core identity

Authorization is resolved from three values only:

1. the persisted Eloquent subject;
2. the current workspace scope, or Kinship's global scope;
3. the permission name.

Permissions are global definitions, unique by name. Roles are unique by name within a workspace scope. Role assignments inherit the role's workspace. Direct user permissions store the workspace scope on the pivot, so a direct grant in workspace A cannot leak into workspace B.

A workspace-aware subject with no resolvable workspace fails closed.

## Roles and permissions

```php
$manager = Role::create(['name' => 'manager']);
$approve = Permission::create(['name' => 'loan.approve']);
$manager->givePermissionTo($approve);
$user->assignRoles($manager);

$user->hasRole('manager');
$user->hasPermission('loan.approve');
```

Role lookups and role assignment are restricted to the subject's current workspace. Permission definitions are shared, while direct grants are attached to the subject's current workspace scope.

## Acting roles

Acting roles are stored by subject and workspace fingerprint. Switching workspace cannot reuse an acting role selected in another workspace. `replace` mode evaluates only the assumed role; `merge` adds it to normal grants.

## Cache safety

The flattened permission set is cached by subject and workspace context. Definition, scope, and subject-scope version tokens make stale entries unreachable after package-managed mutations. Custom pivot models also invalidate cache versions when consumers mutate Kinship Eloquent relationships directly. Raw SQL that bypasses Eloquent also bypasses those events and must rotate the relevant cache explicitly or avoid authorization-table writes outside Kinship.
