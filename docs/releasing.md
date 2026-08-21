# Releasing Kinship

Kinship is developed inside a host application, but its release repository must
contain only the contents of this package directory. Never copy the host
application's Git history, environment files, generated caches, or dependencies.

## Prepare the standalone repository

1. Copy the contents of `packages/Tetranyble/Kinship` into the root of a new
   repository.
2. Do not copy `vendor`, `composer.lock`, `.phpstan.cache`, `.phpunit.cache`, or
   `.phpunit.result.cache`. They are ignored and reproducible. Keep
   `phpstan-stubs`; it normalizes Eloquent's evolving analyzer metadata across
   the supported Laravel releases.
3. Set the package repository URL and support links in `composer.json` once the
   permanent repository URL exists.
4. Install development dependencies with PHP 8.2:

   ```bash
   composer install
   ```

5. Run the complete local gate:

   ```bash
   composer check
   ```

6. Push the repository and confirm the GitHub Actions compatibility matrix is
   green.

## Publish a release

1. Move the entries under `Unreleased` in `CHANGELOG.md` into a versioned,
   dated section.
2. Choose a semantic version. Publish `1.0.0` only when the documented public
   API is ready for backward-compatible maintenance; otherwise use a pre-1.0 or
   prerelease version.
3. Validate the Composer distribution before tagging:

   ```bash
   composer archive --format=tar
   tar -tf tetranyble-kinship-*.tar
   ```

   The archive must not contain dependencies, test caches, the lock file, or
   host-application files.
4. Create and push an annotated tag matching the version, for example `v1.0.0`.
5. Register the repository with Packagist, or confirm its update hook processed
   the new tag.
6. In a disposable Laravel application, require the tagged package and exercise
   installation, migration, seeding, global authorization, and—when enabled—
   workspace-scoped authorization.

Do not add a `version` field to `composer.json`; Packagist derives versions from
Git tags.
