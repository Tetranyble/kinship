# Kinship

Kinship is a guard-aware role and permission package for Laravel 9–12 on PHP 8.2 or newer. Workspace authorization is optional: ordinary applications only add the user concern, while workspace applications can opt in with a small model contract, configuration mapping, or a custom resolver.

## Design guarantees

- Workspace behavior is off unless a subject contract, complete mapping, or explicit configuration enables it.
- Every authorization operation is scoped by an immutable guard/workspace context.
- Permission and acting-role caches are isolated by that context.
- A workspace-aware user without a resolved workspace fails closed.
- The roles schema is stable in both application modes and supports integer, UUID, ULID, and string workspace identifiers.
- Role and permission soft deletion is enforced by the models.
- Host applications own their user and workspace models.

For deeper treatment, see:

- [Authorization](docs/authorization.md)
- [Permission caching and grant sources](docs/caching-and-sources.md)
- [Support/admin user impersonation](docs/impersonation.md)
- [Workspace integration](docs/workspaces.md)
- [Configuration and schema](docs/configuration.md)
- [Release process](docs/releasing.md)

## Installation

Install the package from Packagist:

```bash
composer require tetranyble/kinship
php artisan migrate
```

For local path-repository development before the first tagged release:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/Tetranyble/Kinship",
            "options": { "symlink": true }
        }
    ]
}
```

```bash
composer require tetranyble/kinship:@dev
php artisan migrate
```

Laravel discovers `KinshipServiceProvider` automatically. Publish configuration or migrations when the application must own them:

```bash
php artisan vendor:publish --tag=kinship-config
php artisan vendor:publish --tag=kinship-migrations
```

Set `kinship.migrations.load` to `false` after publishing migrations so there is one migration owner.

## Opt-in permission catalog

Kinship never seeds authorization data during installation, migration, or application boot. The catalog is disabled by default, and application-model discovery has a separate disabled-by-default switch. To use the supplied starter matrix, publish the configuration and explicitly enable it:

```php
// config/kinship.php
'catalog' => [
    'enabled' => true,
    // ...
],
```

Preview and seed a non-workspace application:

```bash
php artisan kinship:seed --dry-run
php artisan kinship:seed
```

The default catalog contains `user`, `role`, and `permission` resources with seven abilities and four starter roles:

| Role | Grants |
| --- | --- |
| `viewer` | index and view (read-only) |
| `contributor` | read, create, and update |
| `manager` | contributor grants plus delete and restore |
| `owner` | every catalog permission, including permanent delete |

The resource shorthand is optional. To own the complete permission vocabulary, set `resources` to an empty array and list arbitrary names. Names are opaque to Kinship, so dot, colon, or domain-specific conventions all work:

```php
'catalog' => [
    'enabled' => true,
    'resources' => [],
    'permissions' => [
        'invoice:read',
        'invoice:approve' => 'Approve Invoice',
        'payment:refund' => [
            'label' => 'Refund Payment',
            'group' => 'payments',
        ],
    ],
    'roles' => [
        'finance-reviewer' => [
            'permissions' => ['invoice:*', 'payment:refund'],
        ],
    ],
],
```

Applications migrating from a model-scanning permission system can opt into the compatibility adapter. It scans only concrete Eloquent models and only while the explicit seed command runs:

```php
'catalog' => [
    'enabled' => true,
    'discovery' => [
        'enabled' => true,
        'path' => 'Models',
        'namespace' => null, // derives App\Models from the application namespace
    ],
],
```

Discovered, configured-resource, and explicit permissions are merged, so an old application can migrate gradually.

In a workspace application the scope must be deliberate:

```bash
php artisan kinship:seed --workspace=01J5ZX4M3T8JH9YB2X6QK7P4NW
php artisan kinship:seed --global
```

The command is idempotent and additive by default. `--sync` makes the configured matrix authoritative for the configured roles, while leaving unrelated roles and permission definitions untouched. See [Configuration and schema](docs/configuration.md#permission-catalog) for custom catalog classes and all command options.

## Ordinary application

Add the concern to the authenticatable user model:

```php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tetranyble\Kinship\Concerns\HasRolesAndPermissions;

class User extends Authenticatable
{
    use HasRolesAndPermissions;
}
```

No workspace interface or relationship is required. Roles created without a workspace value are stored in Kinship's internal global scope and are unique by name, guard, and that scope.

```php
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;

$manager = Role::create([
    'name' => 'manager',
    'label' => 'Manager',
    'guard_name' => 'web',
]);

$approve = Permission::create([
    'name' => 'loan.approve',
    'label' => 'Approve loans',
    'group' => 'loan',
    'guard_name' => 'web',
]);

$manager->givePermissionTo($approve);
$user->assignRoles($manager);

$user->hasRole('manager');             // true
$user->hasPermission('loan.approve');  // true
```

## Workspace application: model-contract path

The user model opts in by implementing `WorkspaceSubject`. Only the identifier is required, following the same integration style as contracts such as JWT subject models.

```php
use Tetranyble\Kinship\Concerns\HasRolesAndPermissions;
use Tetranyble\Kinship\Contracts\WorkspaceSubject;

class User extends Authenticatable implements WorkspaceSubject
{
    use HasRolesAndPermissions;

    public function getWorkspaceIdentifier(): int|string|null
    {
        return $this->workspace_id;
    }
}
```

Any host Eloquent model can mark itself as a workspace:

```php
use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Contracts\Workspace as WorkspaceContract;

class Organization extends Model implements WorkspaceContract
{
    public function getWorkspaceIdentifier(): int|string
    {
        return $this->getKey();
    }
}
```

Relationships are conveniences, not domain requirements. For the common belongs-to design, configure the model and keys, then use the supplied traits:

```php
// config/kinship.php
'workspace' => [
    'enabled' => null,
    'mapping' => [
        'model' => App\Models\Organization::class,
        'relationship' => 'organization',
        'subject_foreign_key' => 'organization_id',
        'workspace_owner_key' => 'id',
        'role_foreign_key' => 'organization_id',
    ],
],
```

```php
class User extends Authenticatable implements WorkspaceSubject
{
    use HasRolesAndPermissions;
    use \Tetranyble\Kinship\Concerns\BelongsToWorkspace;
}

class Organization extends Model implements WorkspaceContract
{
    use \Tetranyble\Kinship\Concerns\IsWorkspace;
}
```

The traits provide `kinshipWorkspace()`, `kinshipUsers()`, and `kinshipRoles()` without forcing those methods into the identity contracts.

## Configuration-only workspace mapping

Applications that cannot modify their user model can provide the complete mapping shown above. Kinship reads the configured foreign key first and falls back to the configured relationship. A partial mapping throws during startup/use instead of silently weakening authorization.

## Custom workspace resolution

Domain-, request-, session-, or middleware-selected workspaces use the Strategy extension point:

```php
use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Contracts\WorkspaceResolver;

final class CurrentWorkspaceResolver implements WorkspaceResolver
{
    public function resolve(Model $subject): int|string|null
    {
        return app(CurrentWorkspace::class)->id();
    }
}
```

```php
'workspace' => [
    'enabled' => true,
    'resolver' => App\Authorization\CurrentWorkspaceResolver::class,
],
```

Returning `null` while workspace mode is active denies all workspace roles. It never falls back to global roles.

## Role and permission API

```php
$user->assignRoles('manager');                 // additive
$user->syncRoles('manager', 'reviewer');       // replaces current-context roles only
$user->removeRoles('reviewer');

$user->assignPermissions('loan.view');         // direct, additive
$user->syncPermissions('loan.view');
$user->removePermissions('loan.view');

$user->hasRole('manager');
$user->hasAnyRole('manager', 'reviewer');
$user->hasAllRoles('manager', 'reviewer');

$user->hasPermission('loan.view');
$user->hasAnyPermission('loan.view', 'loan.approve');
$user->hasAllPermissions('loan.view', 'loan.approve');
```

`roles()` is guard/workspace scoped. `allRoles()` is the explicitly named unscoped persistence relationship and should not be used for authorization decisions.

Permissions are global definitions scoped by guard. Direct user permissions therefore apply across that user's workspaces for the same guard; use role permissions when the grant must vary per workspace.

Permission checks resolve direct and role grants through one SQL `UNION`, then cache the flattened names through Laravel's configured cache store. A warm check on a fresh user instance performs no authorization query. Applications can append a `PermissionGrantSource` for teams or any other domain-owned grant mechanism without adding team schema to Kinship. See [Permission caching and grant sources](docs/caching-and-sources.md).

## Acting roles

```php
if ($user->actAs('manager')) {
    // The acting role is active only for this user, guard, and workspace.
}

$user->getActingRole();
$user->isActingAs();
$user->stopActingAs();

// The clearer role-assumption API is equivalent:
$user->assumeRole('manager');
$user->assumedRole();
$user->isAssumingRole();
$user->stopAssumingRole();
```

This changes the authorization role context, not the authenticated user. The
default `replace` mode suspends normal grants while a role is assumed. Applications
can explicitly select `merge` mode to add the role to normal access. Unassigned
acting roles are denied by default; enable them only for an authorized preview
workflow.

## User impersonation

User impersonation is a separate, disabled-by-default capability for authorized
support workflows. It switches Laravel's authenticated identity to the target
without unlocking or modifying that account:

```php
$support->impersonate($lockedUser, 'Ticket SUP-1234');

auth()->user(); // $lockedUser
auth()->user()->stopImpersonating();
```

The package provides Gate authorization, a required reason, stateful-guard and
session isolation, expiration, actor restoration, audit events, and a dedicated
middleware alias. Applications explicitly attach that middleware, and may add a
new subclass for continuous MFA, lock-state, suspension, compliance, or
route-specific conditions; existing middleware remains unchanged. Stateless
authentication uses a separate middleware and the typed
`StatelessImpersonationTokenBroker` adapter contract; it is never attached to
the `web` group. See
[Support/admin user impersonation](docs/impersonation.md).

## Middleware

```php
Route::get('/loans', LoanController::class)
    ->middleware(['auth:web', 'kinship.role:manager,reviewer']);

Route::post('/loans/{loan}/approve', ApproveLoanController::class)
    ->middleware(['auth:web', 'kinship.permission:loan.approve']);

Route::get('/api/reports', ReportController::class)
    ->middleware(['auth:api', 'kinship.permission:report.view']);
```

Put Laravel's `auth:<guard>` middleware before Kinship. With `kinship.guard=null`, Laravel selects the guard for the request: `auth:api` evaluates roles and permissions whose `guard_name` is `api`, while `auth:web` evaluates `web`. The middleware expression is `auth:api`; the guard value stored in Kinship and passed to `kinship:seed --guard=api` is only `api`.

Comma-separated Kinship arguments have any-of semantics. JSON denials return HTTP 403 with `status` and `message` fields.

## Quality commands

```bash
composer test
composer analyse
composer format
composer check
```

The CI matrix covers Laravel 9/Testbench 7 through Laravel 12/Testbench 10. Laravel 9 is supported for compatibility, but it is upstream end-of-life; applications must assess unresolved framework advisories and plan an upgrade.

## License

MIT
