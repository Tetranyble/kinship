# Changelog

All notable changes to Kinship will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and releases use [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
