<?php

namespace Tetranyble\Kinship\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Tetranyble\Kinship\Catalog\PermissionCatalogSeeder;
use Tetranyble\Kinship\Contracts\GuardResolver;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

final class SeedPermissionCatalogCommand extends Command
{
    protected $signature = 'kinship:seed
        {--workspace= : Workspace identifier to receive the starter roles}
        {--guard= : Guard name; defaults to Kinship or Laravel auth configuration}
        {--global : Explicitly seed roles in the global scope}
        {--sync : Replace permissions on managed roles instead of adding missing grants}
        {--dry-run : Validate and show the planned matrix without writing}';

    protected $description = 'Seed Kinship permissions and starter roles from the opt-in catalog';

    public function handle(
        PermissionCatalogSeeder $seeder,
        WorkspaceConfiguration $workspaces,
        GuardResolver $guards,
    ): int {
        if (! (bool) config('kinship.catalog.enabled', false)) {
            $this->error('Kinship catalog seeding is disabled. Set kinship.catalog.enabled to true first.');

            return self::FAILURE;
        }

        $workspace = $this->option('workspace');
        $workspace = is_string($workspace) && trim($workspace) !== '' ? trim($workspace) : null;
        $global = (bool) $this->option('global');

        if ($workspace !== null && $global) {
            $this->error('Use either --workspace or --global, not both.');

            return self::INVALID;
        }

        $applicationUsesWorkspaces = $workspaces->enabledFor();

        if ($workspace !== null && ! $applicationUsesWorkspaces) {
            $this->error('Workspace seeding requires Kinship workspace mode to be enabled.');

            return self::FAILURE;
        }

        if ($applicationUsesWorkspaces && $workspace === null && ! $global) {
            $this->error('This application uses workspaces. Supply --workspace=<identifier> or explicitly use --global.');

            return self::FAILURE;
        }

        $guardOption = $this->option('guard');

        try {
            $guard = $guards->resolve(
                requestedGuard: is_string($guardOption) ? $guardOption : null,
            );
            $result = $seeder->seed(
                $guard,
                $workspace,
                workspaceScoped: $workspace !== null,
                sync: (bool) $this->option('sync'),
                dryRun: (bool) $this->option('dry-run'),
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Metric', 'Count'], [
            ['Permissions', $result->permissions],
            ['Roles', $result->roles],
            ['Matrix grants', $result->assignments],
            ['Attached', $result->attached],
            ['Detached', $result->detached],
            ['Restored', $result->restored],
        ]);

        $scope = $workspace === null ? 'global' : "workspace [{$workspace}]";
        $verb = $result->dryRun ? 'Validated' : 'Seeded';
        $this->info("{$verb} Kinship catalog for guard [{$guard}] in {$scope} scope.");

        return self::SUCCESS;
    }
}
