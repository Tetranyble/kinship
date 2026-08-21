<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Support\WorkspaceMode;
use Tetranyble\Kinship\Tests\Fixtures\User;
use Tetranyble\Kinship\Tests\Fixtures\Workspace;
use Tetranyble\Kinship\Tests\PackageTestCase;

class WorkspaceDisabledTest extends PackageTestCase
{
    use RefreshDatabase;

    public function test_workspace_scoping_and_schema_are_disabled_by_default(): void
    {
        $this->assertNull(config('kinship.workspace.enabled'));
        $this->assertFalse(WorkspaceMode::enabled());
        $this->assertTrue(Schema::hasColumn('roles', 'workspace_id'));
        $this->assertFalse(Schema::hasColumn('users', 'workspace_id'));

        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $globalRole = Role::query()->create([
            'name' => 'admin',
            'label' => 'Admin',
            'guard_name' => 'web',
        ]);
        $scopedRole = Role::query()->create([
            'name' => 'workspace-admin',
            'label' => 'Workspace admin',
            'guard_name' => 'web',
            'workspace_id' => '99',
        ]);

        $user->allRoles()->syncWithoutDetaching([$globalRole->id, $scopedRole->id]);

        $this->assertSame('__kinship_global__', $globalRole->workspace_id);
        $this->assertTrue(Role::query()->forWorkspace(99)->whereKey($scopedRole)->exists());
        $this->assertTrue($user->hasRole($globalRole));
        $this->assertFalse($user->hasRole($scopedRole));
        $this->assertSame([$globalRole->id], $user->roles()->pluck('roles.id')->all());
    }

    public function test_partial_workspace_mapping_is_rejected(): void
    {
        config(['kinship.workspace.mapping.model' => Workspace::class]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kinship workspace mapping requires [relationship].');

        WorkspaceMode::enabled();
    }

    public function test_global_role_names_are_database_unique(): void
    {
        Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);

        $this->expectException(QueryException::class);

        Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
    }
}
