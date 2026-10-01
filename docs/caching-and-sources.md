# Permission caching and grant sources

Kinship resolves effective permission names from configured `PermissionGrantSource` query providers and unions them into one database query. The built-in source combines workspace-scoped direct user grants, user-role grants, direct group grants, and group-role grants. All four branches are composed into one SQL `UNION`, so multiple group memberships do not introduce N+1 authorization queries.

Each cache entry is isolated by subject model/key and immutable workspace context. Kinship uses three rotating version keys:

- definition version: permission definition changes;
- workspace-scope version: role definition/grant changes and group role/permission changes that may affect many subjects;
- subject/scope version: subject-specific role, permission, or group-membership changes.

Rotating a version makes older permission entries unreachable without wildcard deletion. Cache failure falls back to the authoritative database query.

Package mutation APIs and Kinship custom pivot models rotate the appropriate versions. Direct SQL against authorization tables bypasses Eloquent events and is outside that guarantee.

Custom grant sources implement `PermissionGrantSource` and return a query selecting one string column aliased as `name`. All query sources must use the same database connection so Kinship can compose them efficiently with SQL `UNION`.


## Group invalidation

Adding or removing a subject from a group rotates only that subject/workspace generation. Changing a group role or direct group permission rotates the workspace generation because the change may affect every member. Kinship does not enumerate group members or scan cache keys; each affected subject rebuilds lazily on its next authorization check.

Effective role names/tokens use a separate cached value but the same workspace and subject generations, so role middleware and `hasRole()` remain warm-cache operations too.
