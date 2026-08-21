# Permission caching and grant sources

Kinship's authorization hot path resolves a flat set of permission names. It does not hydrate every role and permission model for each check.

## Query shape

The built-in source combines direct and role-derived grants with SQL `UNION`:

1. `permission_user -> permissions`
2. `role_user -> roles -> permission_role -> permissions`
3. every application-provided `PermissionGrantSource`

Each branch filters the active guard, excludes soft-deleted records, and applies the resolved workspace to roles. SQL `UNION` removes duplicates in the database. A cold check therefore uses one database round trip regardless of the number of grant sources; warm checks use the shared cache and no authorization query.

`hasPermission()`, `hasAnyPermission()`, and `hasAllPermissions()` use an in-memory hash set after resolution, giving exact-name checks constant-time lookup. Catalog wildcard patterns are expanded when roles are seeded; stored wildcard permissions do not implicitly authorize arbitrary names at runtime.

`allPermissions()` deliberately hydrates Eloquent models and therefore performs a permission query when called. Use the check APIs or `allPermissionNames()` on request hot paths.

## Laravel cache integration

```php
'cache' => [
    'enabled' => true,
    'store' => null,
    'prefix' => 'kinship',
    'ttl' => 3600,
],
```

`store=null` uses Laravel's configured default cache store. Changing the host application's cache configuration from file/database to Redis or another Laravel store requires no Kinship change. Set `store` only to isolate authorization data in a named Laravel cache store.

Cache reads use three version tokens fetched together:

- guard version: permission definition changes;
- guard/workspace version: role or shared-source grant changes;
- subject/guard version: direct grants and membership changes.

The versions are part of a hashed cache key containing the subject model/key and immutable guard/workspace context. Rotating a version makes old entries unreachable; they expire naturally at `ttl`. This avoids scanning or deleting every affected user's key when a role or team changes.

Empty permission sets are cached safely. Cache read failures fall back to the database. Invalidation failures are not hidden because silently retaining an authorization grant is unsafe; the configured TTL remains the upper bound for entries changed outside Kinship's APIs.

Kinship automatically invalidates on:

- role assignment, synchronization, and removal;
- direct permission assignment, synchronization, and removal;
- role permission mutation through Kinship model methods;
- role or permission create/update/delete/restore events;
- permission catalog seeding.

`forgetKinshipAuthorizationCache()` invalidates both the current object's request-local cache and its shared subject/guard version.

## Application-owned grant sources

Teams, groups, plans, subscriptions, and relationship-based grants belong to the application. Kinship does not create their tables or dictate their relationships. Implement `PermissionGrantSource` and append it to `permission_sources`.

```php
namespace App\Authorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tetranyble\Kinship\Contracts\PermissionGrantSource;
use Tetranyble\Kinship\Support\AuthorizationContext;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

final class TeamPermissionSource implements PermissionGrantSource
{
    public function __construct(private WorkspaceConfiguration $workspaces) {}

    public function query(Model $subject, AuthorizationContext $context): ?Builder
    {
        $scope = $context->workspaceScope($this->workspaces);
        if ($scope === null) {
            return null;
        }

        $query = DB::table('team_user as tu')
            ->join('teams as t', 't.id', '=', 'tu.team_id')
            ->join('permission_team as pt', 'pt.team_id', '=', 't.id')
            ->join('permissions as p', 'p.id', '=', 'pt.permission_id')
            ->where('tu.user_id', $subject->getKey())
            ->where('p.guard_name', $context->guard)
            ->whereNull('p.deleted_at')
            ->whereNull('t.deleted_at')
            ->select('p.name as name');

        if ($context->workspaceEnabled) {
            $query->where('t.workspace_id', $scope);
        }

        return $query;
    }
}
```

```php
'permission_sources' => [
    Tetranyble\Kinship\Permissions\DatabasePermissionGrantSource::class,
    App\Authorization\TeamPermissionSource::class,
],
```

Every source must:

- return a query selecting exactly one string column aliased as `name`;
- use the same database connection as the other sources so SQL `UNION` is possible;
- enforce the active guard and workspace boundary;
- exclude soft-deleted or inactive domain records;
- index its membership and permission pivot lookup columns.

Kinship cannot observe mutations in application-owned tables. After adding/removing a team member, invalidate that subject. After changing a team's permissions, invalidate the shared scope:

```php
use Tetranyble\Kinship\Cache\PermissionCacheInvalidator;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

$cache = app(PermissionCacheInvalidator::class);

// Membership or another user-specific grant changed.
$cache->invalidateSubject($user, 'api');

// A team's grants changed for every member in this workspace.
$cache->invalidateScope('api', (string) $team->workspace_id);

// In a global application, use Kinship's configured global scope value.
$cache->invalidateScope(
    'web',
    app(WorkspaceConfiguration::class)->globalScopeValue,
);
```

Application code that changes Kinship pivots through raw SQL must perform the same invalidation. Prefer package mutation methods where possible.

## Recommended indexes for a team source

- `team_user`: primary/unique `(team_id, user_id)` plus index `(user_id, team_id)`
- `permission_team`: primary/unique `(team_id, permission_id)`
- `teams`: index the workspace scope column; include guard if teams are guard-specific

Kinship's supplied pivots include reverse lookup indexes needed by the built-in UNION query.
