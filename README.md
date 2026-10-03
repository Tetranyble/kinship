# Kinship

Kinship is a role and permission package for Laravel 9–13 on PHP 8.2 or newer. Workspace authorization is optional: ordinary applications only add the user concern, while workspace applications can opt in with a small model contract, configuration mapping, or a custom resolver.

## Design guarantees

- Workspace behavior is off unless a subject contract, mapping override, or explicit configuration enables it.
- Every authorization operation is scoped by an immutable workspace context.
- Permission and acting-role caches are isolated by that context.
- A workspace-aware user without a resolved workspace fails closed.
- The roles schema is stable in both application modes and supports integer, UUID, ULID, and string workspace identifiers.
- Role and permission soft deletion is enforced by the models.
- Host applications own their user and workspace models and may also supply their own group/access-group model.

For deeper treatment, see:

- [Authorization](docs/authorization.md)
- [Permission caching and grant sources](docs/caching-and-sources.md)
- [Support/admin user impersonation](docs/impersonation.md)
- [Workspace integration](docs/workspaces.md)
- [Configuration and schema](docs/configuration.md)
- [Release process](docs/releasing.md)
- [Upgrading to 2.x](docs/upgrading-v2.md)

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

## Model factories

The package models expose namespaced factories directly to consuming applications:

```php
use Tetranyble\Kinship\Models\Group;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;

$permission = Permission::factory()->create();
$role = Role::factory()->forWorkspace($workspace)->system()->create();
$group = Group::factory()->forWorkspace($workspace->getKey())->create();
```

`forWorkspace()` accepts a Kinship `Workspace` contract or an integer/string workspace identifier. Roles and groups use the configured workspace column and default to Kinship's global scope when the state is omitted. As with Laravel application factories, Faker must be installed in the consuming application's development dependencies.

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

No workspace interface or relationship is required. Roles created without a workspace value are stored in Kinship's internal global scope and are unique by name and that scope.

```php
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;

$manager = Role::create([
    'name' => 'manager',
    'label' => 'Manager',
]);

$approve = Permission::create([
    'name' => 'loan.approve',
    'label' => 'Approve loans',
    'group' => 'loan',
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

The host application owns the workspace model. Configure it once and opt it into Kinship with the small workspace contract:

```php
// config/kinship.php
'models' => [
    'workspace' => App\Models\Workspace::class,
],
```

```php
use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Concerns\IsWorkspace;
use Tetranyble\Kinship\Contracts\Workspace as WorkspaceContract;

class Workspace extends Model implements WorkspaceContract
{
    use IsWorkspace;
}
```

The trait is optional; applications may implement only `getWorkspaceIdentifier()` when they already own their relationships or use a non-standard key.

Relationships are conveniences, not domain requirements. For the conventional `workspace_id` design, no model mapping is repeated:

```php
// config/kinship.php
'models' => [
    'workspace' => App\Models\Workspace::class,
],

'workspace' => [
    'enabled' => null,
    'subject_foreign_key' => 'workspace_id',
    'role_foreign_key' => 'workspace_id',
    'group_foreign_key' => 'workspace_id',
],
```

```php
class User extends Authenticatable implements WorkspaceSubject
{
    use HasRolesAndPermissions;
    use \Tetranyble\Kinship\Concerns\BelongsToWorkspace;
}

class Workspace extends Model implements WorkspaceContract
{
    use \Tetranyble\Kinship\Concerns\IsWorkspace;
}
```

The traits provide `kinshipWorkspace()`, `kinshipUsers()`, `kinshipRoles()`, and `kinshipGroups()` without forcing those methods into the identity contracts.

## Configuration-only workspace mapping

Applications that cannot modify their user model can configure only the non-standard relationship/key names they need. The workspace model still comes exclusively from `kinship.models.workspace`; it is never duplicated in the mapping block. Kinship reads the mapped foreign key first and, when configured, falls back to the mapped relationship.

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

`roles()` is workspace scoped. `allRoles()` is the explicitly named unscoped persistence relationship and should not be used for authorization decisions.

Permissions are global definitions unique by name. Direct user permissions are stored with the current workspace scope, so a grant in one workspace cannot leak into another workspace.

Permission checks resolve direct user grants, user roles, group permissions, and group roles through one SQL `UNION`, then cache the flattened names through Laravel's configured cache store. A warm check on a fresh user instance performs no authorization query. Effective role checks are cached with the same versioned strategy. Applications can still append additional `PermissionGrantSource` implementations for domain-owned grant mechanisms. See [Permission caching and grant sources](docs/caching-and-sources.md).

## Tenant-scoped groups

The workspace identifier remains Kinship's multi-tenancy boundary. Groups are access/IAM groups *inside* a workspace; they do not replace workspaces or implicitly model departments, branches, projects, or other host-domain structures.

Kinship ships a conventional `Group` model, but applications may use an existing model such as `Team`, `AccessGroup`, or `SecurityGroup` without extending Kinship's model:

```php
// config/kinship.php
'models' => [
    'group' => App\Models\Team::class,
],

'workspace' => [
    'group_foreign_key' => 'tenant_id',
],

'group' => [
    'lookup_columns' => ['code'],
],
```

```php
use Illuminate\Database\Eloquent\Model;
use Tetranyble\Kinship\Concerns\IsGroup;
use Tetranyble\Kinship\Contracts\Group as GroupContract;

class Team extends Model implements GroupContract
{
    use IsGroup;

    protected $table = 'access_teams';
}
```

The `Group` contract requires only a stable group identifier and workspace identifier. `name`, `label`, `description`, `is_system`, timestamps, soft deletes, the literal `workspace_id` column name, and BIGINT identifiers are **not** authorization requirements. The default migration supplies those conventional fields; applications integrating an existing schema should publish/adapt the migrations (or disable package migration loading) and map their column names.

```php
$credit = Group::create([
    'name' => 'credit-operations',
    'workspace_id' => $workspace->getKey(),
]);

$credit->assignRoles('loan-reviewer');
$credit->givePermissionTo('customer.view');
$credit->addMembers($ada, $leonard);

$leonard->hasRole('loan-reviewer'); // inherited
$leonard->hasPermission('customer.view'); // inherited
```

A subject may be provisioned into groups in several tenants, but authorization activates only memberships whose group's workspace equals the current authorization workspace. User-side `assignGroups()`/`syncGroups()` resolve groups only in the current workspace. Group-role links are strictly same-workspace. Nested groups and deny rules are intentionally not implemented. See [Groups and inherited authorization](docs/groups.md).

## Acting roles

```php
if ($user->actAs('manager')) {
    // The acting role is active only for this user and workspace.
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

Put Laravel authentication middleware before Kinship so `$request->user()` is established first. The authentication mechanism (`web`, `api`, Sanctum, Passport, JWT, etc.) does not change Kinship authorization: the same subject in the same workspace receives the same grants.

Comma-separated Kinship arguments have any-of semantics. JSON denials return HTTP 403 with `status` and `message` fields.

## Quality commands

```bash
composer test
composer analyse
composer format
composer check
```

The test suite includes schema-contract checks, workspace contract/trait integration, non-standard workspace/group foreign keys, host-owned group models, tenant isolation, group inheritance, cache invalidation, warm-cache zero-query behavior, middleware, catalog, acting roles, and impersonation. The CI matrix covers Laravel 9/Testbench 7 through Laravel 13/Testbench 11. Laravel 9 is supported for compatibility, but it is upstream end-of-life; applications must assess unresolved framework advisories and plan an upgrade.

## License

MIT
