<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Kinship\Contracts\WorkspaceResolver;
use Tetranyble\Kinship\Contracts\WorkspaceSubject;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Support\WorkspaceMode;
use Tetranyble\Kinship\Tests\Fixtures\Workspace;
use Tetranyble\Kinship\Tests\Fixtures\WorkspaceUser;
use Tetranyble\Kinship\Tests\WorkspacePackageTestCase;

class WorkspaceEnabledTest extends WorkspacePackageTestCase
{
    use RefreshDatabase;

    public function test_workspace_schema_is_created_after_opt_in(): void
    {
        $this->assertNull(config('kinship.workspace.enabled'));
        $this->assertTrue(WorkspaceMode::enabled());
        $this->assertTrue(Schema::hasColumn('roles', 'workspace_id'));
        $this->assertTrue(Schema::hasColumn('users', 'workspace_id'));
    }

    public function test_workspace_contracts_only_require_identity_while_traits_offer_relationships(): void
    {
        $workspaceContract = new \ReflectionClass(\Tetranyble\Kinship\Contracts\Workspace::class);
        $subjectContract = new \ReflectionClass(WorkspaceSubject::class);

        $this->assertSame(['getWorkspaceIdentifier'], array_map(
            fn (\ReflectionMethod $method): string => $method->getName(),
            $workspaceContract->getMethods(),
        ));
        $this->assertSame(['getWorkspaceIdentifier'], array_map(
            fn (\ReflectionMethod $method): string => $method->getName(),
            $subjectContract->getMethods(),
        ));
    }

    public function test_workspace_and_subject_contracts_define_both_sides(): void
    {
        $workspace = new Workspace;
        $workspace->setAttribute('id', 42);
        $user = new WorkspaceUser;
        $user->setAttribute('workspace_id', 42);

        $this->assertSame(42, $workspace->getWorkspaceIdentifier());
        $this->assertSame(42, $user->getWorkspaceIdentifier());
        $this->assertInstanceOf(HasMany::class, $workspace->kinshipUsers());
        $this->assertInstanceOf(HasMany::class, $workspace->kinshipRoles());
        $this->assertInstanceOf(BelongsTo::class, $user->kinshipWorkspace());
    }

    public function test_subject_relationship_can_resolve_the_workspace_contract(): void
    {
        $workspace = new Workspace;
        $workspace->setAttribute('id', 84);
        $user = new WorkspaceUser;
        $user->setRelation('kinshipWorkspace', $workspace);

        $this->assertSame(84, app(WorkspaceResolver::class)->resolve($user));
    }

    public function test_string_workspace_identifiers_are_supported(): void
    {
        $workspaceId = '01J5ZX4M3T8JH9YB2X6QK7P4NW';
        $user = WorkspaceUser::query()->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'workspace_id' => $workspaceId,
        ]);
        $role = Role::query()->create([
            'name' => 'admin',
            'guard_name' => 'web',
            'workspace_id' => $workspaceId,
        ]);

        $user->assignRoles($role);

        $this->assertTrue($user->hasRole('admin'));
        $this->assertSame($workspaceId, $role->workspace_id);
    }

    public function test_workspace_subject_without_an_identifier_fails_closed(): void
    {
        $user = WorkspaceUser::query()->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'workspace_id' => null,
        ]);
        $role = Role::query()->create([
            'name' => 'admin',
            'guard_name' => 'web',
            'workspace_id' => '__kinship_global__',
        ]);
        $user->allRoles()->syncWithoutDetaching([$role->id]);

        $this->assertFalse($user->hasRole('admin'));
        $this->assertTrue($user->roles()->doesntExist());
    }

    public function test_contract_activation_does_not_hide_a_partial_mapping(): void
    {
        config(['kinship.workspace.mapping.model' => Workspace::class]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kinship workspace mapping requires [relationship].');

        WorkspaceMode::enabled();
    }
}
