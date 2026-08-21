<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Tests\WorkspacePackageTestCase;

class WorkspacePermissionCatalogTest extends WorkspacePackageTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kinship.catalog.enabled' => true]);
    }

    public function test_workspace_app_requires_an_explicit_command_scope(): void
    {
        $this->artisan('kinship:seed')->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('roles', 0);
    }

    public function test_command_seeds_the_same_role_matrix_independently_per_workspace(): void
    {
        $this->artisan('kinship:seed', ['--workspace' => 'workspace-a'])->assertSuccessful();
        $this->artisan('kinship:seed', ['--workspace' => 'workspace-b'])->assertSuccessful();

        $this->assertDatabaseCount('permissions', 21);
        $this->assertDatabaseCount('roles', 8);
        $this->assertSame(4, Role::query()->forWorkspace('workspace-a')->count());
        $this->assertSame(4, Role::query()->forWorkspace('workspace-b')->count());
        $this->assertSame(
            21,
            Role::query()->forWorkspace('workspace-a')->where('name', 'owner')->firstOrFail()->permissions()->count(),
        );
    }

    public function test_global_scope_must_be_requested_explicitly_in_a_workspace_app(): void
    {
        $this->artisan('kinship:seed', ['--global' => true])->assertSuccessful();

        $this->assertSame(4, Role::query()->forWorkspace(null)->count());
    }
}
