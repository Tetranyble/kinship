<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tetranyble\Kinship\Events\GroupMemberAdded;
use Tetranyble\Kinship\Events\GroupMemberRemoved;
use Tetranyble\Kinship\Models\Group;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Support\KinshipModels;
use Tetranyble\Kinship\Tests\Fixtures\Team;
use Tetranyble\Kinship\Tests\Fixtures\WorkspaceUser;
use Tetranyble\Kinship\Tests\WorkspacePackageTestCase;

class GroupAuthorizationTest extends WorkspacePackageTestCase
{
    use RefreshDatabase;

    public function test_group_schema_is_tenant_scoped_and_has_required_pivots(): void
    {
        $this->assertTrue(Schema::hasColumns('groups', [
            'id',
            'name',
            'label',
            'description',
            'is_system',
            'workspace_id',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('group_user', ['group_id', 'user_id', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('group_role', ['group_id', 'role_id', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('group_permission', ['group_id', 'permission_id', 'created_at', 'updated_at']));
    }

    public function test_group_contract_is_minimal_and_trait_allows_host_owned_models(): void
    {
        $contract = new \ReflectionClass(\Tetranyble\Kinship\Contracts\Group::class);

        $this->assertSame(
            ['getKinshipGroupIdentifier', 'getKinshipWorkspaceIdentifier'],
            array_map(
                fn (\ReflectionMethod $method): string => $method->getName(),
                $contract->getMethods(),
            ),
        );

        config(['kinship.models.group' => Team::class]);

        $team = Team::query()->create([
            'name' => 'host-owned-ops',
            'label' => 'Host Owned Ops',
            'workspace_id' => 10,
        ]);

        $this->assertSame(Team::class, KinshipModels::group());
        $this->assertSame($team->getKey(), $team->getKinshipGroupIdentifier());
        $this->assertSame(10, $team->getKinshipWorkspaceIdentifier());
    }

    public function test_host_owned_group_model_participates_in_permission_inheritance_without_extending_package_group(): void
    {
        config(['kinship.models.group' => Team::class]);

        $user = $this->user(10);
        $team = Team::query()->create([
            'name' => 'host-owned-credit',
            'label' => 'Host Owned Credit',
            'workspace_id' => 10,
        ]);
        $permission = $this->permission('loan.host-review');

        $team->givePermissionTo($permission)->addMembers($user);

        $this->assertTrue($user->fresh()->hasPermission('loan.host-review'));
        $this->assertTrue($user->fresh()->hasGroup($team));
    }

    public function test_group_scalar_lookup_columns_are_host_configurable(): void
    {
        Schema::table('groups', function ($table): void {
            $table->string('code')->nullable()->index();
        });
        config([
            'kinship.models.group' => Team::class,
            'kinship.group.lookup_columns' => ['code'],
        ]);

        $user = $this->user(10);
        $team = Team::query()->create([
            'name' => 'display-name-is-not-the-lookup',
            'code' => 'CRD-OPS',
            'workspace_id' => 10,
        ]);

        $user->assignGroups('CRD-OPS');

        $this->assertTrue($user->hasGroup('CRD-OPS'));
        $this->assertTrue($user->hasGroup($team));
    }

    public function test_configured_group_model_must_be_an_eloquent_model_implementing_group_contract(): void
    {
        config(['kinship.models.group' => Model::class]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kinship.models.group');

        KinshipModels::group();
    }

    public function test_group_direct_permission_is_inherited_by_members(): void
    {
        $user = $this->user(10);
        $group = $this->group('credit-operations', 10);
        $permission = $this->permission('loan.review');

        $group->givePermissionTo($permission)->addMembers($user);

        $this->assertTrue($user->hasPermission('loan.review'));
        $this->assertTrue($user->hasGroup($group));
    }

    public function test_group_role_is_inherited_for_role_and_permission_checks(): void
    {
        $user = $this->user(10);
        $group = $this->group('treasury-operators', 10);
        $role = $this->role('settlement-operator', 10);
        $permission = $this->permission('settlement.release');
        $role->givePermissionTo($permission);

        $group->assignRoles($role)->addMembers($user);

        $this->assertTrue($user->hasRole('settlement-operator'));
        $this->assertTrue($user->hasPermission('settlement.release'));
        $this->assertEmpty($user->roles()->get()->all(), 'Inherited roles must not masquerade as direct role assignments.');
    }

    public function test_multiple_group_memberships_union_all_grants(): void
    {
        $user = $this->user(10);
        $credit = $this->group('credit', 10);
        $fraud = $this->group('fraud', 10);
        $creditPermission = $this->permission('loan.review');
        $fraudPermission = $this->permission('fraud.case.view');

        $credit->givePermissionTo($creditPermission)->addMembers($user);
        $fraud->givePermissionTo($fraudPermission)->addMembers($user);

        $this->assertTrue($user->hasAllPermissions('loan.review', 'fraud.case.view'));
    }

    public function test_group_membership_isolated_by_current_tenant(): void
    {
        $user = $this->user(10);
        $workspaceOne = $this->group('reviewers', 10);
        $workspaceTwo = $this->group('reviewers', 20);
        $one = $this->permission('workspace-one.review');
        $two = $this->permission('workspace-two.review');
        $workspaceOne->givePermissionTo($one)->addMembers($user);

        $user->update(['workspace_id' => 20]);
        $workspaceTwo->givePermissionTo($two)->addMembers($user);
        $user->update(['workspace_id' => 10]);

        $this->assertTrue($user->hasPermission('workspace-one.review'));
        $this->assertFalse($user->hasPermission('workspace-two.review'));

        $user->update(['workspace_id' => 20]);
        $this->assertFalse($user->hasPermission('workspace-one.review'));
        $this->assertTrue($user->hasPermission('workspace-two.review'));
    }

    public function test_identity_can_be_provisioned_into_another_tenant_group_without_cross_tenant_leakage(): void
    {
        $user = $this->user(10);
        $foreign = $this->group('foreign', 20);
        $permission = $this->permission('foreign.execute');
        $foreign->givePermissionTo($permission);

        // Group-side membership provisioning may target an identity that also belongs
        // to other tenants. The current workspace decides whether the grant is active.
        $foreign->users()->attach($user->id);

        $this->assertFalse($user->hasPermission('foreign.execute'));

        $user->update(['workspace_id' => 20]);
        $this->assertTrue($user->fresh()->hasPermission('foreign.execute'));
    }

    public function test_user_side_group_assignment_cannot_target_a_foreign_tenant_group(): void
    {
        $user = $this->user(10);
        $foreign = $this->group('foreign', 20);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cross-workspace group membership');

        $user->assignGroups($foreign);
    }

    public function test_cross_tenant_group_role_assignment_is_rejected(): void
    {
        $group = $this->group('credit', 10);
        $foreignRole = $this->role('foreign-approver', 20);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cross-workspace group role assignment');

        $group->roles()->attach($foreignRole->id);
    }

    public function test_membership_mutation_busts_only_the_subject_cache_for_next_request(): void
    {
        $user = $this->user(10);
        $group = $this->group('credit', 10);
        $permission = $this->permission('loan.review');
        $group->givePermissionTo($permission);

        $this->assertFalse($user->hasPermission('loan.review'));

        $group->addMembers($user);
        $this->assertTrue($user->fresh()->hasPermission('loan.review'));

        $group->removeMembers($user);
        $this->assertFalse($user->fresh()->hasPermission('loan.review'));
    }

    public function test_sync_groups_replaces_only_current_tenant_memberships(): void
    {
        $user = $this->user(10);
        $tenantTenA = $this->group('tenant-ten-a', 10);
        $tenantTenB = $this->group('tenant-ten-b', 10);
        $tenantTwenty = $this->group('tenant-twenty', 20);

        $tenantTenA->addMembers($user);
        $tenantTwenty->addMembers($user);

        $user->syncGroups($tenantTenB);

        $this->assertFalse($user->hasGroup($tenantTenA));
        $this->assertTrue($user->hasGroup($tenantTenB));

        $user->update(['workspace_id' => 20]);
        $this->assertTrue($user->fresh()->hasGroup($tenantTwenty));
    }

    public function test_group_permission_revoke_invalidates_warm_member_authorization(): void
    {
        $user = $this->user(10);
        $group = $this->group('credit', 10);
        $permission = $this->permission('loan.approve');
        $group->givePermissionTo($permission)->addMembers($user);

        $this->assertTrue($user->hasPermission('loan.approve'));

        $group->revokePermissionTo($permission);

        $this->assertFalse($user->fresh()->hasPermission('loan.approve'));
    }

    public function test_group_role_change_invalidates_warm_effective_role_cache(): void
    {
        $user = $this->user(10);
        $group = $this->group('operations', 10);
        $role = $this->role('operator', 10);
        $group->addMembers($user);

        $this->assertFalse($user->hasRole('operator'));

        $group->assignRoles($role);
        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole('operator'));
        $this->assertSame(['operator'], $fresh->allRoleNames()->all());

        $group->removeRoles($role);
        $this->assertFalse($user->fresh()->hasRole('operator'));
    }

    public function test_group_and_role_workspace_scope_are_immutable(): void
    {
        $group = $this->group('operations', 10);
        $role = $this->role('operator', 10);

        try {
            $group->update(['workspace_id' => 20]);
            $this->fail('Expected immutable group workspace scope to reject the update.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('group workspace scope is immutable', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('role workspace scope is immutable');
        $role->update(['workspace_id' => 20]);
    }

    public function test_group_grant_change_invalidates_warm_member_authorization(): void
    {
        $user = $this->user(10);
        $group = $this->group('credit', 10);
        $group->addMembers($user);
        $permission = $this->permission('loan.approve');

        $this->assertFalse($user->hasPermission('loan.approve'));

        $group->givePermissionTo($permission);

        $this->assertTrue($user->fresh()->hasPermission('loan.approve'));
    }

    public function test_warm_group_permission_check_does_not_query_database(): void
    {
        $user = $this->user(10);
        $group = $this->group('credit', 10);
        $group->givePermissionTo($this->permission('loan.review'))->addMembers($user);

        $cold = $user->fresh();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertTrue($cold->hasPermission('loan.review'));
        $this->assertCount(1, DB::getQueryLog());

        $warm = $user->fresh();
        DB::flushQueryLog();
        $this->assertTrue($warm->hasPermission('loan.review'));
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_warm_inherited_role_check_does_not_query_database(): void
    {
        $user = $this->user(10);
        $group = $this->group('ops', 10);
        $role = $this->role('operator', 10);
        $group->assignRoles($role)->addMembers($user);

        $cold = $user->fresh();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertTrue($cold->hasRole('operator'));
        $this->assertCount(1, DB::getQueryLog());

        $warm = $user->fresh();
        DB::flushQueryLog();
        $this->assertTrue($warm->hasRole('operator'));
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_soft_deleted_group_stops_authorizing_members(): void
    {
        $user = $this->user(10);
        $group = $this->group('temporary-ops', 10);
        $permission = $this->permission('ops.execute');
        $group->givePermissionTo($permission)->addMembers($user);
        $this->assertTrue($user->hasPermission('ops.execute'));

        $group->delete();

        $this->assertFalse($user->fresh()->hasPermission('ops.execute'));
    }

    public function test_group_membership_events_are_dispatched(): void
    {
        Event::fake([GroupMemberAdded::class, GroupMemberRemoved::class]);
        $user = $this->user(10);
        $group = $this->group('ops', 10);

        $group->addMembers($user);
        $group->removeMembers($user);

        Event::assertDispatched(GroupMemberAdded::class);
        Event::assertDispatched(GroupMemberRemoved::class);
    }

    private function user(int $workspaceId): WorkspaceUser
    {
        return WorkspaceUser::query()->create([
            'name' => 'User '.$workspaceId.' '.uniqid(),
            'email' => uniqid().'@example.test',
            'workspace_id' => $workspaceId,
        ]);
    }

    private function group(string $name, int $workspaceId): Group
    {
        return Group::query()->create([
            'name' => $name,
            'label' => str($name)->headline()->toString(),
            'workspace_id' => $workspaceId,
        ]);
    }

    private function role(string $name, int $workspaceId): Role
    {
        return Role::query()->create([
            'name' => $name,
            'label' => str($name)->headline()->toString(),
            'workspace_id' => $workspaceId,
        ]);
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
