<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Tests\Fixtures\WorkspaceUser;
use Tetranyble\Kinship\Tests\WorkspacePackageTestCase;

class WorkspacePermissionCacheTest extends WorkspacePackageTestCase
{
    use RefreshDatabase;

    public function test_shared_cache_is_isolated_and_invalidated_by_workspace_scope(): void
    {
        $user = WorkspaceUser::query()->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'workspace_id' => 10,
        ]);
        $workspaceOne = Role::query()->create(['name' => 'reviewer', 'workspace_id' => 10]);
        $workspaceTwo = Role::query()->create(['name' => 'reviewer', 'workspace_id' => 20]);
        $first = $this->permission('workspace-one.view');
        $second = $this->permission('workspace-two.view');
        $newFirst = $this->permission('workspace-one.approve');
        $workspaceOne->givePermissionTo($first);
        $workspaceTwo->givePermissionTo($second);
        $user->allRoles()->attach([$workspaceOne->id, $workspaceTwo->id]);

        $this->assertTrue(WorkspaceUser::query()->findOrFail($user->id)->hasPermission('workspace-one.view'));

        WorkspaceUser::query()->whereKey($user)->update(['workspace_id' => 20]);
        $this->assertTrue(WorkspaceUser::query()->findOrFail($user->id)->hasPermission('workspace-two.view'));

        $workspaceOne->givePermissionTo($newFirst);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertTrue(WorkspaceUser::query()->findOrFail($user->id)->hasPermission('workspace-two.view'));
        $this->assertCount(1, DB::getQueryLog()); // user lookup only; workspace-two grant stayed warm

        WorkspaceUser::query()->whereKey($user)->update(['workspace_id' => 10]);
        $fresh = WorkspaceUser::query()->findOrFail($user->id);
        DB::flushQueryLog();
        $this->assertTrue($fresh->hasPermission('workspace-one.approve'));
        $this->assertCount(1, DB::getQueryLog()); // workspace-one grant cache was invalidated
    }

    public function test_direct_permissions_are_scoped_to_the_current_workspace(): void
    {
        $user = WorkspaceUser::query()->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'workspace_id' => 10,
        ]);
        $permission = $this->permission('invoice.approve');

        $user->assignPermissions($permission);
        $this->assertTrue($user->hasPermission('invoice.approve'));
        $this->assertDatabaseHas('permission_user', [
            'permission_id' => $permission->id,
            'user_id' => $user->id,
            'workspace_id' => '10',
        ]);

        $user->setAttribute('workspace_id', 20);
        $this->assertFalse($user->hasPermission('invoice.approve'));

        $user->assignPermissions($permission);
        $this->assertTrue($user->hasPermission('invoice.approve'));
        $this->assertDatabaseHas('permission_user', [
            'permission_id' => $permission->id,
            'user_id' => $user->id,
            'workspace_id' => '20',
        ]);
    }

    public function test_direct_relationship_attach_automatically_writes_the_current_workspace_scope(): void
    {
        $user = WorkspaceUser::query()->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'workspace_id' => 10,
        ]);
        $permission = $this->permission('invoice.export');

        $user->userPermissions()->attach($permission->id);

        $this->assertDatabaseHas('permission_user', [
            'permission_id' => $permission->id,
            'user_id' => $user->id,
            'workspace_id' => '10',
        ]);
        $this->assertTrue($user->hasPermission('invoice.export'));

        $user->setAttribute('workspace_id', 20);
        $this->assertFalse($user->hasPermission('invoice.export'));
    }

    private function permission(string $name): Permission
    {
        return Permission::query()->create([
            'name' => $name,
            'label' => str($name)->headline()->toString(),
            'group' => str($name)->before('.')->toString(),
        ]);
    }
}
