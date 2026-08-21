<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Kinship\Contracts\WorkspaceResolver;
use Tetranyble\Kinship\Models\Role;
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

        $user = MappedWorkspaceUser::query()->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'organization_id' => 42,
        ]);
        $role = Role::query()->create([
            'name' => 'admin',
            'label' => 'Admin',
            'guard_name' => 'web',
            'organization_scope_id' => 42,
        ]);

        $user->assignRoles('admin');

        $this->assertTrue($user->hasRole($role));
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
    }
}
