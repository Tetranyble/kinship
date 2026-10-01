# Workspace integration

Workspace authorization is an optional capability layered over Kinship's RBAC core. A non-workspace application uses the same package, tables, and APIs without implementing any workspace type.

## Activation rules

`kinship.workspace.enabled` accepts three values:

| Value | Behavior |
| --- | --- |
| `false` | Force global authorization, even if models implement workspace contracts |
| `true` | Force workspace authorization; normally paired with a custom resolver or mapping |
| `null` | Enable for a `WorkspaceSubject`, a configured user model implementing that contract, or a mapping override |

The database shape never depends on this decision. The roles table always has a non-null string scope column. Global applications use the configured sentinel; workspace applications store a canonical string representation of their integer, UUID, ULID, or string identifier.

This separates two concerns:

- model/configuration capabilities decide runtime authorization behavior;
- a stable schema prevents configuration changes from creating missing-column failures.

## Identity contracts

The workspace marker is deliberately small:

```php
interface Workspace
{
    public function getWorkspaceIdentifier(): int|string;
}
```

The user/actor marker is equally small:

```php
interface WorkspaceSubject
{
    public function getWorkspaceIdentifier(): int|string|null;
}
```

Neither contract requires an Eloquent relationship. This allows domain-selected workspaces, session state, subdomain context, aggregate roots, and custom persistence arrangements without pretending every application has the same relationship topology.

The host tenant model is configured once at `kinship.models.workspace`:

```php
'models' => [
    'workspace' => App\Models\Workspace::class,
],
```

Kinship does not ship or own a workspace table/model. The host `Workspace` model must extend Eloquent `Model` and implement the workspace contract. `IsWorkspace` is an optional convenience trait, not a base class requirement.

An active workspace subject returning `null` fails closed: role queries intentionally return no results. Global roles are not an implicit fallback.

## Direct foreign-key integration

For the conventional user-belongs-to-workspace design:

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
use Tetranyble\Kinship\Concerns\BelongsToWorkspace;
use Tetranyble\Kinship\Concerns\HasRolesAndPermissions;
use Tetranyble\Kinship\Contracts\WorkspaceSubject;

class User extends Authenticatable implements WorkspaceSubject
{
    use HasRolesAndPermissions;
    use BelongsToWorkspace;
}
```

`BelongsToWorkspace` supplies:

- `getWorkspaceIdentifier()` from the configured subject foreign key;
- `kinshipWorkspace()` as a `BelongsTo` relation;
- relationship fallback when the foreign key is absent but a workspace model is loaded.

The configured `models.workspace` model must implement the workspace marker:

```php
use Tetranyble\Kinship\Concerns\IsWorkspace;
use Tetranyble\Kinship\Contracts\Workspace as WorkspaceContract;

class Workspace extends Model implements WorkspaceContract
{
    use IsWorkspace;
}
```

`IsWorkspace` supplies the identifier and optional `kinshipUsers()`, `kinshipRoles()`, and `kinshipGroups()` inverse relationships.

The contract does not require a literal `id` or `workspace_id` field. A host model may use UUID/ULID/string owner keys and may implement `getWorkspaceIdentifier()` directly. `kinship.models.workspace` is authoritative; a stale or legacy `workspace.mapping.model` entry is ignored rather than creating a second model source of truth.

## Manual contract implementation

The trait is optional. Implement only the identity method when the application already owns its relationships:

```php
class User extends Authenticatable implements WorkspaceSubject
{
    use HasRolesAndPermissions;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function getWorkspaceIdentifier(): int|string|null
    {
        return $this->workspace_id;
    }
}
```

The method may return the currently selected membership workspace instead of a persisted user column. That makes many-to-many membership designs first-class.

## Configuration adapter

If the host user model cannot implement package contracts, supply all mapping fields:

```php
'models' => [
    'workspace' => App\Models\Workspace::class,
],

'workspace' => [
    'mapping' => [
        'relationship' => 'workspace',
        'subject_foreign_key' => 'tenant_uuid',
        'workspace_owner_key' => 'uuid',
        'role_foreign_key' => 'tenant_role_uuid',
        'group_foreign_key' => 'tenant_group_uuid',
    ],
],
```

Resolution order is:

1. Read `tenant_uuid` from the subject.
2. If absent, resolve the configured `workspace` relationship.
3. Validate that the related object is the configured `models.workspace` model and implements the workspace contract.
4. Read its workspace identifier.

Mapping fields are optional overrides. Unspecified foreign keys fall back to the top-level workspace keys, and an unspecified owner key falls back to the configured Workspace model's Eloquent primary key. Role and group scope keys are independent so existing tables may use different physical tenant columns without changing the authorization boundary.

## Custom resolver strategy

Use a custom `WorkspaceResolver` when context comes from middleware, a request domain, a session-selected membership, or another package:

```php
final class DomainWorkspaceResolver implements WorkspaceResolver
{
    public function __construct(private CurrentWorkspace $current) {}

    public function resolve(Model $subject): int|string|null
    {
        return $this->current->identifier();
    }
}
```

```php
'workspace' => [
    'enabled' => true,
    'resolver' => App\Authorization\DomainWorkspaceResolver::class,
],
```

The service provider resolves the class through Laravel's container, so constructor dependencies are supported. An application may alternatively bind `WorkspaceResolver::class` before Kinship registers.

For complete context replacement, implement and bind `AuthorizationContextResolver`. It receives the subject and must return an `AuthorizationContext`.

## Identifier rules

Workspace identifiers may be integers or non-empty strings. Internally Kinship compares their canonical string representation strictly. Thus integer `42` and database string `"42"` describe the same persisted scope without PHP loose-equality behavior.

The identifier must not equal `kinship.workspace.global_scope_value`. Keep that sentinel private to Kinship and choose a value that cannot be a real application key.

## Global versus unresolved context

These states are intentionally distinct:

| State | Stored/query scope | Authorization |
| --- | --- | --- |
| Workspace feature disabled | global sentinel | global roles may authorize |
| Workspace enabled with identifier | canonical identifier | only matching workspace roles authorize |
| Workspace enabled without identifier | none | fails closed |

This prevents an incomplete workspace login or resolver failure from inheriting global privileges.

## Context switching

Every permission and acting-role cache entry is keyed by subject and workspace fingerprint. Reusing the same user instance after a workspace switch therefore cannot return permissions from the previous workspace.

`roles()` always scopes queries to the current context. `allRoles()` is intentionally unscoped and exists for persistence/administrative tooling. Never use `allRoles()` to make an authorization decision.

If application code changes external resolver state and has also manually loaded Eloquent relationships, call:

```php
$user->forgetKinshipAuthorizationCache();
```

Kinship's own authorization methods query their scoped relationships and do not trust an old loaded `roles` collection.

## Direct permissions

Permission definitions are global and unique by name. Direct user permissions are stored with the current workspace scope, so they do not cross workspace boundaries.

For workspace-varying grants, attach the permission to a workspace-scoped role instead.

## Catalog seeding by scope

When the optional permission catalog is enabled, workspace applications must name the intended role scope:

```bash
php artisan kinship:seed --workspace=workspace-123
```

Run the command once for each workspace that needs the starter roles. Permission definitions are global and are reused; each command creates or updates the roles only in the named workspace. Use `--global` only when the application intentionally needs global roles:

```bash
php artisan kinship:seed --global
```

Kinship will not infer a current workspace in a console process and will not seed every workspace automatically.

## Security checklist

- Resolve workspace identity from trusted server-side state, not an unchecked request parameter.
- Return `null` when there is no verified workspace; Kinship will deny workspace roles.
- Keep `allow_unassigned` acting roles disabled unless the workflow explicitly requires role preview or assumption.
- Use `roles()`, `hasRole()`, and permission APIs for decisions; reserve `allRoles()` for administration.
- Configure mapping and column names before migrating.
- Test switching between two workspaces on the same authenticated user instance.
