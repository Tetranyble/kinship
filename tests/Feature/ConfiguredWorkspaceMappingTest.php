<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Kinship\Contracts\WorkspaceResolver;
use Tetranyble\Kinship\Models\Group;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;
use Tetranyble\Kinship\Support\WorkspaceMode;
use Tetranyble\Kinship\Tests\ConfiguredWorkspacePackageTestCase;
use Tetranyble\Kinship\Tests\Fixtures\MappedWorkspaceUser;
use Tetranyble\Kinship\Tests\Fixtures\TraitWorkspaceUser;
use Tetranyble\Kinship\Tests\Fixtures\Workspace;

class ConfiguredWorkspaceMappingTest extends ConfiguredWorkspacePackageTestCase
{
    use RefreshDatabase;

    public function test_complete_model_relationship_and_key_mapping_opts_in(): void
    {
        $this->assertTrue(WorkspaceMode::enabled());
        $this->assertTrue(Schema::hasColumn('users', 'organization_id'));
        $this->assertTrue(Schema::hasColumn('roles', 'organization_scope_id'));
        $this->assertTrue(Schema::hasColumn('groups', 'organization_group_scope_id'));

        $user = MappedWorkspaceUser::query()->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'organization_id' => 42,
        ]);
        $role = Role::query()->create([
            'name' => 'admin',
            'label' => 'Admin',
            'organization_scope_id' => 42,
        ]);

        $user->assignRoles('admin');

        $this->assertTrue($user->hasRole($role));
    }

    public function test_role_and_group_workspace_foreign_keys_can_be_mapped_independently(): void
    {
        $user = MappedWorkspaceUser::query()->create([
            'name' => 'Grace',
            'email' => 'grace@example.test',
            'organization_id' => 42,
        ]);
        $group = Group::query()->create([
            'name' => 'credit-ops',
            'organization_group_scope_id' => 42,
        ]);
        $role = Role::query()->create([
            'name' => 'credit-reviewer',
            'organization_scope_id' => 42,
        ]);
        $permission = Permission::query()->create([
            'name' => 'credit.review',
        ]);

        $role->givePermissionTo($permission);
        $group->assignRoles($role)->addMembers($user);

        $this->assertTrue($user->hasRole('credit-reviewer'));
        $this->assertTrue($user->hasPermission('credit.review'));
    }

    public function test_models_workspace_is_authoritative_even_if_legacy_mapping_contains_a_model_key(): void
    {
        config(['kinship.workspace.mapping.model' => Model::class]);
        app()->forgetInstance(WorkspaceConfiguration::class);

        $user = new TraitWorkspaceUser;
        $user->setAttribute('organization_id', 21);

        $this->assertInstanceOf(Workspace::class, $user->kinshipWorkspace()->getRelated());
    }

    public function test_mapped_relationship_can_supply_the_workspace_owner_key(): void
    {
        $workspace = new Workspace;
        $workspace->setAttribute('id', 84);
        $user = new MappedWorkspaceUser;
        $user->setRelation('workspace', $workspace);

        $this->assertSame(84, app(WorkspaceResolver::class)->resolve($user));
    }

    public function test_relationship_traits_use_the_configured_model_and_keys(): void
    {
        $user = new TraitWorkspaceUser;
        $user->setAttribute('organization_id', 21);

        $this->assertSame(21, $user->getWorkspaceIdentifier());
        $this->assertInstanceOf(BelongsTo::class, $user->kinshipWorkspace());

        $workspace = new Workspace;
        $workspace->setAttribute('id', 21);

        $this->assertSame('organization_id', $workspace->kinshipUsers()->getForeignKeyName());
        $this->assertSame('organization_scope_id', $workspace->kinshipRoles()->getForeignKeyName());
        $this->assertSame('organization_group_scope_id', $workspace->kinshipGroups()->getForeignKeyName());
    }
}
