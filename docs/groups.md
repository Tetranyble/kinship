# Groups and inherited authorization

Kinship groups are IAM/access groups that live **inside** a workspace. The workspace identifier remains the package's multi-tenancy boundary. A group never replaces a workspace and does not imply a department, branch, project, or other host-domain structure.

A host application may label Kinship groups as teams, desks, committees, access groups, security groups, or project groups in its UI.

## Authorization graph

For a subject in the current workspace, effective permissions are:

```text
direct user permissions
UNION direct user role permissions
UNION direct group permissions
UNION group role permissions
```

Effective roles are:

```text
direct user roles
UNION group roles
```

Users can belong to multiple groups. Kinship does not assign a primary group and does not implement nested groups. Avoiding nested groups keeps inherited privilege paths explicit and prevents recursive authorization graphs.

## Host-owned Group model

Kinship ships `Tetranyble\Kinship\Models\Group` as a conventional default. Applications are not required to inherit from it.

Configure an existing Eloquent model:

```php
// config/kinship.php
'models' => [
    'group' => App\Models\Team::class,
],
```

The model must implement the small contract:

```php
interface Group
{
    public function getKinshipGroupIdentifier(): int|string;

    public function getKinshipWorkspaceIdentifier(): int|string;
}
```

For a conventional persisted model, use the optional trait:

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

`IsGroup` supplies:

- the two required identifiers;
- `users()`, `roles()`, and `permissions()` relationships;
- member, role, and permission mutation helpers;
- immutable tenant scope after creation;
- authorization cache invalidation;
- group audit events through the package pivots.

The trait does **not** require soft deletes. A host model may use hard deletes, soft deletes, custom casts, its own timestamps, or no timestamps on the group table. Pivot timestamp behavior remains controlled by Kinship's pivot schema.

## Required semantics versus physical fields

Kinship requires these facts, not these literal database column names:

| Semantic requirement | Required | Physical name fixed? |
| --- | ---: | ---: |
| Persisted group identifier | yes | no |
| Workspace/tenant identifier | yes | no |
| Membership relation | yes when group inheritance is used | pivot names configurable |
| Group-role relation | yes when group roles are used | pivot names configurable |
| Group-permission relation | yes when direct group permissions are used | pivot names configurable |
| `name` | no | no |
| `label` | no | no |
| `description` | no | no |
| `is_system` | no | no |
| `deleted_at` | no | no |
| BIGINT primary key | no | no |

The default package migration creates a conventional `groups` table with `id`, `name`, `label`, `description`, `is_system`, a workspace column, timestamps, and soft deletes. Those fields are defaults for the package-owned model, not requirements imposed on host-owned models.

For an existing schema, publish/adapt migrations or set `kinship.migrations.load=false` after the application takes ownership of them.

## Workspace column mapping

Group and role workspace columns may differ:

```php
'workspace' => [
    'subject_foreign_key' => 'workspace_id',
    'role_foreign_key' => 'tenant_scope',
    'group_foreign_key' => 'tenant_id',
],
```

Or override only non-standard values in the mapping block:

```php
'workspace' => [
    'mapping' => [
        'group_foreign_key' => 'organization_uuid',
    ],
],
```

`kinship.models.workspace` remains the single authoritative workspace model.

## Group lookup fields

Passing a Group model instance is always the least ambiguous API:

```php
$user->assignGroups($team);
```

Scalar lookup is optional convenience. The package-owned model defaults to:

```php
'group' => [
    'lookup_columns' => ['name', 'label'],
],
```

An existing host model can use its own field:

```php
'group' => [
    'lookup_columns' => ['code'],
],
```

Then:

```php
$user->assignGroups('CRD-OPS');
```

resolves against the group's primary key and configured lookup columns within the current workspace. Ambiguous references are rejected rather than attaching multiple groups.

## Creating and assigning groups

With the package-owned model:

```php
use Tetranyble\Kinship\Models\Group;

$group = Group::create([
    'name' => 'credit-operations',
    'label' => 'Credit Operations',
    'workspace_id' => $workspace->getKey(),
]);

$group->assignRoles('loan-reviewer');
$group->givePermissionTo('customer.view');
$group->addMembers($userA, $userB);
```

The inverse user API is also available:

```php
$user->assignGroups($group);
$user->syncGroups($groupA, $groupB);
$user->removeGroups($groupA);
$user->hasGroup($groupB);
```

`groups()` is current-workspace scoped. `allGroups()` is an explicitly unscoped persistence relationship and must not be used for authorization decisions.

## Tenant isolation

A single user identity may legitimately participate in multiple tenants. Kinship therefore stores membership against the tenant-scoped group rather than treating the user's currently selected workspace as permanent ownership of that identity.

The user-side `assignGroups()`, `syncGroups()`, and `removeGroups()` APIs resolve group references only inside the user's current authorization workspace. Group-side provisioning such as `$group->addMembers($user)` may prepare that same identity for another tenant. That membership is dormant unless that tenant is the active authorization workspace.

Authorization queries independently require the group's workspace identifier to equal the current workspace. A membership in tenant B therefore cannot contribute a role or permission while tenant A is active.

Roles and groups are both tenant-scoped principals, so Kinship rejects attaching a role to a group in another workspace, including raw Eloquent pivot attaches. Group and role workspace scope is immutable after creation. Moving an existing group or role into another tenant could transform existing grants into cross-tenant access, so Kinship requires creating a new principal in the target tenant instead.

Permission definitions remain global. The group that receives the permission is tenant scoped, so the grant itself remains tenant scoped.

For resolver-driven multi-tenancy, Kinship can only enforce the active authorization boundary supplied by the host resolver. The host application remains responsible for deciding which identities are eligible to become members of each tenant.

## Cache invalidation

Adding or removing one member rotates that subject's authorization version for the group's workspace. The next permission/role check rebuilds that subject once and re-caches it.

Changing a group's roles or permissions rotates the workspace authorization version because the mutation can affect every group member. No user enumeration is required and no cache-key scan is performed. Existing members rebuild lazily on their next authorization check.

Warm permission and effective-role checks use the shared cache and do not execute authorization SQL until an invalidation changes the relevant version.

## Audit events

Kinship dispatches:

- `GroupMemberAdded`
- `GroupMemberRemoved`
- `GroupRoleAssigned`
- `GroupRoleRevoked`
- `GroupPermissionGranted`
- `GroupPermissionRevoked`

Each event contains the related model identifiers and workspace scope. The host application can listen for these events and persist actor/request metadata in its own audit trail.

## Why no nested groups or deny rules?

Kinship keeps group inheritance additive and non-recursive. Nested groups create transitive privilege paths and cycle/invalidation concerns. Explicit deny rules introduce precedence rules between user, role, group, and policy grants. Applications that need contextual denial should use Laravel policies or a dedicated policy engine rather than making the RBAC graph ambiguous.
