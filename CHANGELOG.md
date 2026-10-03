# Changelog

All notable changes to Kinship will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and releases use [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

## [2.0.1] - 2026-10-03

### Added

- Added package-owned factories for the default Role, Permission, and Group models, including workspace and system states for roles and groups.

## [2.0.0] - 2026-10-01

### Added

- Added tenant-scoped IAM groups. Users may belong to multiple groups and inherit both group roles and direct group permissions inside the active workspace.
- Added cached effective-role resolution, group membership/grant audit events, and tenant-isolation checks for group-role assignments.
- Added the `Group` contract and optional `IsGroup` trait so applications can use host-owned `Team`, `AccessGroup`, or security-group models without extending Kinship's default Group model.
- Added independent configurable group workspace keys and configurable scalar group lookup columns for existing schemas.
- Expanded regression coverage for default schema contracts, Workspace contract/trait behavior, independent role/group tenant columns, host-owned Group models, cross-tenant isolation, cache invalidation, and warm-cache zero-query authorization.

### Changed

- Workspace integration now uses the host-owned `kinship.models.workspace` model as the single source of truth. The model must implement the small `Workspace` contract; the optional `IsWorkspace` trait supplies conventional identity and inverse user/role/group relationships. Workspace mapping no longer carries a duplicate model class.
- Group integration is now contract-driven rather than subclass-driven. `kinship.models.group` accepts any Eloquent model implementing the `Group` contract; the default `Group` remains available as a conventional model.
- Workspace role and group storage columns may now be configured independently (`role_foreign_key` and `group_foreign_key`) while sharing the same tenant authorization context.
- Group scalar lookup is configurable through `kinship.group.lookup_columns`; model-instance assignment remains the unambiguous baseline.
- Removed authorization guard scoping. Roles, permissions, grant resolution, catalog seeding, and authorization caches no longer depend on Laravel guard names or `web`/`api` authentication strategy.
- Direct user permissions are now workspace-scoped on the `permission_user` pivot instead of silently applying across all workspaces.
- Authorization middleware now trusts only the request principal established by the host authentication middleware and no longer scans configured guards for another identity.
- String role/permission/group references now fail on ambiguity instead of matching multiple IDs/names/labels.
- Direct subject role/permission mutations now rotate only that subject/workspace cache generation; group role/permission changes rotate the affected workspace generation without enumerating members.

### Fixed

- Permission-to-role mutations now invalidate all affected authorization scopes.
- Added custom pivot models so Eloquent `attach`, `detach`, and `sync` mutations invalidate authorization caches even when callers bypass Kinship convenience methods.
- Added an upgrade migration that refuses to remove legacy guard scoping when duplicate role or permission definitions must first be consolidated.
- Removed the assumption that configured group models use SoftDeletes; hard-deleting host-owned group models are supported without `deleted_at` query failures.

### Breaking

- Removed `GuardResolver`, `ApplicationGuardResolver`, `guard_name` authorization columns, `Role::forGuard()`, `Permission::forGuard()`, `kinship.guard`, and the catalog `--guard` option.
- Existing legacy direct permission grants migrate to the global sentinel scope; applications must explicitly map them to tenant workspaces when that is the intended business rule.

## [1.1.0] - 2026-08-21

### Added

- Laravel 13 compatibility with Testbench 11 and PHPUnit 12 coverage.

## [1.0.0] - 2026-08-21

### Added

- Guard-aware roles and permissions for Laravel 9 through 12.
- Optional workspace-scoped authorization with contract, trait, configured-model,
  and resolver-based integration paths.
- Direct, role-derived, and application-defined permission grant sources.
- Laravel cache-backed permission resolution with scoped version invalidation.
- Compatible `actAs*` and `assumeRole*` role-assumption APIs with safe
  replacement and opt-in merge modes.
- Opt-in support/admin user impersonation with Gate authorization, required
  reasons, expiry, audit events, explicit route middleware, isolated session
  handling, and provider-adapted stateless grants.
- Opt-in permission catalog seeding, custom definitions, legacy model scanning,
  and configurable starter roles.
- Artisan installation and seeding commands.
- Orchestra Testbench coverage, static analysis, formatting checks, and a CI
  compatibility matrix for PHP 8.2 through 8.4.
