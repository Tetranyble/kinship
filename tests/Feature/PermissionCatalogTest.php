<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Tests\PackageTestCase;

class PermissionCatalogTest extends PackageTestCase
{
    use RefreshDatabase;

    public function test_catalog_is_disabled_and_non_mutating_by_default(): void
    {
        $this->artisan('kinship:seed')->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('permissions', 0);
        $this->assertDatabaseCount('roles', 0);
    }

    public function test_command_seeds_the_default_permission_and_starter_role_matrix(): void
    {
        config(['kinship.catalog.enabled' => true]);

        $this->artisan('kinship:seed')->assertSuccessful();

        $this->assertDatabaseCount('permissions', 21);
        $this->assertDatabaseCount('roles', 4);
        $this->assertSame(6, $this->role('viewer')->permissions()->count());
        $this->assertSame(12, $this->role('contributor')->permissions()->count());
        $this->assertSame(18, $this->role('manager')->permissions()->count());
        $this->assertSame(21, $this->role('owner')->permissions()->count());
        $this->assertTrue($this->role('viewer')->permissions()->where('name', 'user.view')->exists());
        $this->assertFalse($this->role('viewer')->permissions()->where('name', 'user.update')->exists());
        $this->assertTrue($this->role('contributor')->permissions()->where('name', 'user.update')->exists());
        $this->assertFalse($this->role('manager')->permissions()->where('name', 'user.force_delete')->exists());
        $this->assertTrue($this->role('owner')->permissions()->where('name', 'user.force_delete')->exists());
        $this->assertDatabaseHas('permissions', [
            'name' => 'user.index',
            'label' => 'User View All',
            'group' => 'user',
        ]);
    }

    public function test_seeding_is_idempotent_and_additive_unless_sync_is_requested(): void
    {
        config(['kinship.catalog.enabled' => true]);
        $this->artisan('kinship:seed')->assertSuccessful();
        $external = Permission::query()->create([
            'name' => 'external.export',
            'label' => 'External Export',
            'group' => 'external',
        ]);
        $viewer = $this->role('viewer');
        $viewer->givePermissionTo($external);

        $this->artisan('kinship:seed')->assertSuccessful();
        $this->assertTrue($viewer->permissions()->whereKey($external)->exists());
        $this->assertDatabaseCount('roles', 4);

        $this->artisan('kinship:seed', ['--sync' => true])->assertSuccessful();
        $this->assertFalse($viewer->permissions()->whereKey($external)->exists());
        $this->assertDatabaseCount('permissions', 22);
    }

    public function test_seeding_restores_managed_soft_deleted_records(): void
    {
        config(['kinship.catalog.enabled' => true]);
        $this->artisan('kinship:seed')->assertSuccessful();
        Permission::query()->where('name', 'user.view')->firstOrFail()->delete();
        $this->role('viewer')->delete();

        $this->artisan('kinship:seed')->assertSuccessful();

        $this->assertNotNull(Permission::query()->where('name', 'user.view')->first());
        $this->assertNotNull(Role::query()->where('name', 'viewer')->first());
    }

    public function test_dry_run_validates_without_writing(): void
    {
        config(['kinship.catalog.enabled' => true]);

        $this->artisan('kinship:seed', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('permissions', 0);
        $this->assertDatabaseCount('roles', 0);
    }

    public function test_resources_abilities_custom_permissions_and_roles_are_configurable(): void
    {
        config([
            'kinship.catalog.enabled' => true,
            'kinship.catalog.resources' => [
                'loan' => ['label' => 'Loan', 'abilities' => ['index', 'view', 'update']],
            ],
            'kinship.catalog.permissions' => [
                ['name' => 'loan.approve', 'label' => 'Approve Loan', 'group' => 'loan'],
            ],
            'kinship.catalog.roles' => [
                'reviewer' => [
                    'permissions' => ['loan.index', 'loan.view', 'loan.approve'],
                ],
            ],
        ]);

        $this->artisan('kinship:seed')->assertSuccessful();

        $this->assertDatabaseCount('permissions', 4);
        $this->assertDatabaseCount('roles', 1);
        $this->assertEqualsCanonicalizing(
            ['loan.index', 'loan.view', 'loan.approve'],
            $this->role('reviewer')->permissions()->pluck('name')->all(),
        );
    }

    public function test_consumers_can_seed_only_their_own_arbitrary_permission_vocabulary(): void
    {
        config([
            'kinship.catalog.enabled' => true,
            'kinship.catalog.resources' => [],
            'kinship.catalog.permissions' => [
                'invoice:read',
                'invoice:approve' => 'Approve Invoice',
                'payment:refund' => [
                    'label' => 'Refund Payment',
                    'group' => 'payments',
                ],
            ],
            'kinship.catalog.roles' => [
                'finance-reviewer' => [
                    'permissions' => ['invoice:*', 'payment:refund'],
                ],
            ],
        ]);

        $this->artisan('kinship:seed')->assertSuccessful();

        $this->assertDatabaseCount('permissions', 3);
        $this->assertDatabaseCount('roles', 1);
        $this->assertEqualsCanonicalizing(
            ['invoice:read', 'invoice:approve', 'payment:refund'],
            $this->role('finance-reviewer')->permissions()->pluck('name')->all(),
        );
        $this->assertDatabaseHas('permissions', [
            'name' => 'invoice:read',
            'label' => 'Invoice Read',
            'group' => 'invoice',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'payment:refund',
            'label' => 'Refund Payment',
            'group' => 'payments',
        ]);
    }

    public function test_resource_shorthand_can_use_a_custom_permission_separator(): void
    {
        config([
            'kinship.catalog.enabled' => true,
            'kinship.catalog.separator' => ':',
            'kinship.catalog.resources' => [
                'invoice' => ['abilities' => ['view', 'update']],
            ],
            'kinship.catalog.permissions' => [],
            'kinship.catalog.roles' => [
                'reviewer' => ['permissions' => ['invoice:*']],
            ],
        ]);

        $this->artisan('kinship:seed')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['invoice:view', 'invoice:update'],
            Permission::query()->pluck('name')->all(),
        );
        $this->assertSame(2, $this->role('reviewer')->permissions()->count());
    }

    public function test_application_model_discovery_is_an_explicit_compatibility_option(): void
    {
        config([
            'kinship.catalog.enabled' => true,
            'kinship.catalog.discovery' => [
                'enabled' => true,
                'path' => dirname(__DIR__).'/Fixtures',
                'namespace' => 'Tetranyble\\Kinship\\Tests\\Fixtures',
            ],
            'kinship.catalog.resources' => [],
            'kinship.catalog.permissions' => ['system:health'],
            'kinship.catalog.roles' => [
                'owner' => ['permissions' => ['*']],
            ],
        ]);

        $this->artisan('kinship:seed')->assertSuccessful();

        $this->assertDatabaseHas('permissions', ['name' => 'user.view']);
        $this->assertDatabaseHas('permissions', ['name' => 'workspace.view']);
        $this->assertDatabaseHas('permissions', ['name' => 'system:health']);
        $this->assertDatabaseMissing('permissions', ['name' => 'fixed_workspace_resolver.view']);
    }

    public function test_unmatched_role_patterns_fail_before_any_database_write(): void
    {
        config([
            'kinship.catalog.enabled' => true,
            'kinship.catalog.resources' => [],
            'kinship.catalog.permissions' => ['invoice:view'],
            'kinship.catalog.roles' => [
                'reviewer' => ['permissions' => ['invoice:approve']],
            ],
        ]);

        $this->artisan('kinship:seed')->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('permissions', 0);
        $this->assertDatabaseCount('roles', 0);
    }

    private function role(string $name): Role
    {
        return Role::query()->where('name', $name)->firstOrFail();
    }
}
