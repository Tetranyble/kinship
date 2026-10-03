<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Tetranyble\Kinship\Database\Factories\GroupFactory;
use Tetranyble\Kinship\Database\Factories\PermissionFactory;
use Tetranyble\Kinship\Database\Factories\RoleFactory;
use Tetranyble\Kinship\Models\Group;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;
use Tetranyble\Kinship\Tests\PackageTestCase;

class FactoryTest extends PackageTestCase
{
    public function test_package_models_expose_their_factories(): void
    {
        $this->assertInstanceOf(GroupFactory::class, Group::factory());
        $this->assertInstanceOf(PermissionFactory::class, Permission::factory());
        $this->assertInstanceOf(RoleFactory::class, Role::factory());
    }

    public function test_factories_create_package_models_with_global_scope_by_default(): void
    {
        $group = Group::factory()->create();
        $permission = Permission::factory()->create();
        $role = Role::factory()->create();
        $configuration = app(WorkspaceConfiguration::class);

        $this->assertSame($configuration->globalScopeValue, $group->getAttribute($configuration->groupForeignKey()));
        $this->assertSame($configuration->globalScopeValue, $role->getAttribute($configuration->roleForeignKey()));
        $this->assertDatabaseHas('permissions', ['id' => $permission->getKey()]);
    }

    public function test_workspace_and_system_states_are_available(): void
    {
        $group = Group::factory()->forWorkspace('workspace-1')->system()->create();
        $role = Role::factory()->forWorkspace('workspace-1')->system()->create();
        $configuration = app(WorkspaceConfiguration::class);

        $this->assertSame('workspace-1', $group->getAttribute($configuration->groupForeignKey()));
        $this->assertSame('workspace-1', $role->getAttribute($configuration->roleForeignKey()));
        $this->assertTrue($group->is_system);
        $this->assertTrue($role->is_system);
    }
}
