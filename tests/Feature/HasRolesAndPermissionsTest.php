<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Tests\Fixtures\WorkspaceUser;
use Tetranyble\Kinship\Tests\WorkspacePackageTestCase;

class HasRolesAndPermissionsTest extends WorkspacePackageTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startSession();
    }

    public function test_roles_are_resolved_within_the_users_workspace_and_guard(): void
    {
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'workspace_id' => 10]);
        $expected = $this->role('admin', workspaceId: 10);
        $otherWorkspace = $this->role('admin', workspaceId: 20);
        $otherGuard = $this->role('admin', workspaceId: 10, guard: 'api');

        $user->assignRoles('admin', $otherWorkspace->id, $otherGuard->id);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $expected->id,
        ]);
        $this->assertSame([$expected->id], $user->fresh()->roles->pluck('id')->all());
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_role_and_direct_permissions_are_merged_with_any_and_all_checks(): void
    {
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'workspace_id' => 10]);
        $role = $this->role('analyst', workspaceId: 10);
        $view = $this->permission('loan.view');
        $approve = $this->permission('loan.approve');
        $role->givePermissionTo($view);

        $user->assignRoles($role)->assignPermissions($approve);

        $this->assertDatabaseHas('permission_role', [
            'role_id' => $role->id,
            'permission_id' => $view->id,
        ]);
        $this->assertDatabaseHas('permission_user', [
            'user_id' => $user->id,
            'permission_id' => $approve->id,
        ]);
        $this->assertEqualsCanonicalizing(
            ['loan.view', 'loan.approve'],
            $user->allPermissionNames()->all(),
        );
        $this->assertTrue($user->hasPermissions('missing', 'loan.view'));
        $this->assertTrue($user->hasAllPermissions('loan.view', 'loan.approve'));
        $this->assertFalse($user->hasAllPermissions('loan.view', 'loan.delete'));
        $this->assertEqualsCanonicalizing(
            ['loan.view', 'loan.approve'],
            $user->permissions()->all(),
        );
    }

    public function test_unassigned_acting_roles_are_denied_by_default_and_can_be_enabled_explicitly(): void
    {
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'workspace_id' => 10]);
        $admin = $this->role('admin', workspaceId: 10);
        $admin->givePermissionTo($this->permission('admin.view'));

        $this->assertFalse($user->actAs($admin));
        $this->assertFalse($user->hasPermission('admin.view'));

        config(['kinship.acting_roles.allow_unassigned' => true]);

        $this->assertTrue($user->actAs($admin));
        $this->assertTrue($user->hasPermission('admin.view'));
        $user->stopActingAs();
        $this->assertFalse($user->hasPermission('admin.view'));
    }

    public function test_assume_role_aliases_preserve_the_acting_role_api(): void
    {
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'workspace_id' => 10]);
        $reviewer = $this->role('reviewer', workspaceId: 10);
        $user->assignRoles($reviewer);

        $this->assertTrue($user->assumeRole('reviewer'));
        $this->assertTrue($user->isAssumingRole());
        $this->assertTrue($reviewer->is($user->assumedRole()));
        $this->assertTrue($user->isActingAs());

        $user->stopAssumingRole();

        $this->assertFalse($user->isActingAs());
    }

    public function test_assumed_role_replaces_normal_access_by_default_and_merge_is_opt_in(): void
    {
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'workspace_id' => 10]);
        $owner = $this->role('owner', workspaceId: 10);
        $reviewer = $this->role('reviewer', workspaceId: 10);
        $owner->givePermissionTo($this->permission('payment.approve'));
        $reviewer->givePermissionTo($this->permission('payment.view'));
        $user->assignRoles($owner, $reviewer)
            ->assignPermissions($this->permission('account.export'));

        $this->assertTrue($user->assumeRole($reviewer));
        $this->assertFalse($user->hasRole('owner'));
        $this->assertTrue($user->hasRole('reviewer'));
        $this->assertFalse($user->hasPermission('payment.approve'));
        $this->assertFalse($user->hasPermission('account.export'));
        $this->assertTrue($user->hasPermission('payment.view'));

        $user->stopAssumingRole();
        config(['kinship.acting_roles.mode' => 'merge']);
        $this->assertTrue($user->actAs($reviewer));

        $this->assertTrue($user->hasRole('owner'));
        $this->assertTrue($user->hasPermission('payment.approve'));
        $this->assertTrue($user->hasPermission('account.export'));
        $this->assertTrue($user->hasPermission('payment.view'));
    }

    public function test_role_sync_removal_and_primary_role_are_workspace_safe(): void
    {
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'workspace_id' => 10]);
        $manager = $this->role('manager', workspaceId: 10);
        $manager->update(['order' => 20]);
        $admin = $this->role('admin', workspaceId: 10);
        $admin->update(['order' => 1]);
        $foreign = $this->role('foreign', workspaceId: 20);

        $user->assignRoles($manager, $admin, $foreign);
        $this->assertSame('admin', $user->primaryRole()?->name);
        $this->assertFalse($user->hasRole('foreign'));

        $user->syncRoles($manager, $foreign);
        $this->assertTrue($user->hasRole('manager'));
        $this->assertFalse($user->hasRole('admin'));

        $user->removeRoles($manager);
        $this->assertFalse($user->hasRole('manager'));
    }

    public function test_permission_can_be_assigned_to_guard_compatible_role_models(): void
    {
        $permission = $this->permission('loan.view');
        $first = $this->role('analyst', workspaceId: 10);
        $second = $this->role('manager', workspaceId: 20);
        $otherGuard = $this->role('api-user', workspaceId: 10, guard: 'api');

        $permission->assignRoles(collect([$first, $second, $otherGuard]));

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $permission->roles()->pluck('roles.id')->all(),
        );
    }

    public function test_permission_cache_is_isolated_when_the_same_user_instance_changes_workspace(): void
    {
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'workspace_id' => 10]);
        $first = $this->role('first', workspaceId: 10);
        $second = $this->role('second', workspaceId: 20);
        $first->givePermissionTo($this->permission('workspace-one.view'));
        $second->givePermissionTo($this->permission('workspace-two.view'));
        $user->allRoles()->syncWithoutDetaching([$first->id, $second->id]);

        $this->assertTrue($user->hasPermission('workspace-one.view'));
        $this->assertFalse($user->hasPermission('workspace-two.view'));

        $user->setAttribute('workspace_id', 20);

        $this->assertFalse($user->hasPermission('workspace-one.view'));
        $this->assertTrue($user->hasPermission('workspace-two.view'));
        $this->assertSame([$second->id], $user->roles()->pluck('roles.id')->all());
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $user->allRoles()->pluck('roles.id')->all());
    }

    public function test_acting_role_cache_and_session_are_bound_to_the_current_context(): void
    {
        config(['kinship.acting_roles.allow_unassigned' => true]);
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'workspace_id' => 10]);
        $role = $this->role('admin', workspaceId: 10);
        $role->givePermissionTo($this->permission('admin.view'));

        $this->assertTrue($user->actAs($role));
        $this->assertTrue($user->hasPermission('admin.view'));

        $user->setAttribute('workspace_id', 20);

        $this->assertNull($user->getActingRole());
        $this->assertFalse($user->hasPermission('admin.view'));
    }

    public function test_permission_cache_is_isolated_when_the_guard_changes(): void
    {
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'workspace_id' => 10]);
        $web = $this->role('web-role', workspaceId: 10);
        $api = $this->role('api-role', workspaceId: 10, guard: 'api');
        $web->givePermissionTo($this->permission('web.view'));
        $api->givePermissionTo($this->permission('api.view', guard: 'api'));
        $user->allRoles()->syncWithoutDetaching([$web->id, $api->id]);

        $this->assertTrue($user->hasPermission('web.view'));
        $this->assertFalse($user->hasPermission('api.view'));

        $user->setAttribute('guard_name', 'api');

        $this->assertFalse($user->hasPermission('web.view'));
        $this->assertTrue($user->hasPermission('api.view'));
    }

    public function test_null_package_guard_follows_laravels_runtime_active_guard(): void
    {
        config([
            'kinship.guard' => null,
            'auth.guards.api' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ]);
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'workspace_id' => 10]);
        $web = $this->role('web-role', workspaceId: 10);
        $api = $this->role('api-role', workspaceId: 10, guard: 'api');
        $web->givePermissionTo($this->permission('dashboard.view'));
        $api->givePermissionTo($this->permission('dashboard.view', guard: 'api'));
        $user->allRoles()->sync([$web->id, $api->id]);

        auth()->shouldUse('api');

        $this->assertTrue($user->hasRole('api-role'));
        $this->assertFalse($user->hasRole('web-role'));
        $this->assertTrue($user->hasPermission('dashboard.view'));
        $this->assertSame(['api-role'], $user->roles()->pluck('name')->all());

        auth()->shouldUse('web');

        $this->assertTrue($user->hasRole('web-role'));
        $this->assertFalse($user->hasRole('api-role'));
    }

    public function test_role_and_permission_creation_persist_laravels_runtime_active_guard(): void
    {
        config([
            'kinship.guard' => null,
            'auth.guards.api' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ]);
        auth()->shouldUse('api');

        $role = Role::query()->create(['name' => 'api-author']);
        $permission = Permission::query()->create([
            'name' => 'article.create',
            'label' => 'Create Article',
            'group' => 'article',
        ]);

        $this->assertSame('api', $role->guard_name);
        $this->assertSame('api', $permission->guard_name);
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'guard_name' => 'api']);
        $this->assertDatabaseHas('permissions', ['id' => $permission->id, 'guard_name' => 'api']);
    }

    public function test_sync_roles_only_replaces_roles_in_the_current_context(): void
    {
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'workspace_id' => 10]);
        $first = $this->role('first', workspaceId: 10);
        $replacement = $this->role('replacement', workspaceId: 10);
        $foreign = $this->role('foreign', workspaceId: 20);
        $user->allRoles()->syncWithoutDetaching([$first->id, $foreign->id]);

        $user->syncRoles($replacement);

        $this->assertEqualsCanonicalizing(
            [$replacement->id, $foreign->id],
            $user->allRoles()->pluck('roles.id')->all(),
        );
    }

    public function test_soft_deleted_roles_and_permissions_do_not_authorize(): void
    {
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'workspace_id' => 10]);
        $role = $this->role('analyst', workspaceId: 10);
        $rolePermission = $this->permission('loan.view');
        $directPermission = $this->permission('loan.approve');
        $role->givePermissionTo($rolePermission);
        $user->assignRoles($role)->assignPermissions($directPermission);

        $this->assertTrue($user->hasAllPermissions('loan.view', 'loan.approve'));

        $role->delete();
        $directPermission->delete();
        $user->forgetKinshipAuthorizationCache();

        $this->assertFalse($user->hasPermission('loan.view'));
        $this->assertFalse($user->hasPermission('loan.approve'));
    }

    public function test_permission_assignment_is_additive_and_sync_is_explicit(): void
    {
        $permission = $this->permission('loan.view');
        $first = $this->role('first', workspaceId: 10);
        $second = $this->role('second', workspaceId: 20);

        $permission->assignRoles($first);
        $permission->assignRoles($second);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $permission->roles()->pluck('roles.id')->all());

        $permission->syncRoles($second);
        $this->assertSame([$second->id], $permission->roles()->pluck('roles.id')->all());
    }

    private function role(string $name, int $workspaceId, string $guard = 'web'): Role
    {
        return Role::query()->create([
            'name' => $name,
            'label' => ucfirst($name),
            'workspace_id' => $workspaceId,
            'guard_name' => $guard,
        ]);
    }

    private function permission(string $name, string $guard = 'web'): Permission
    {
        return Permission::query()->create([
            'name' => $name,
            'label' => ucfirst($name),
            'group' => str($name)->before('.')->toString(),
            'guard_name' => $guard,
        ]);
    }
}
