<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Tests\Fixtures\User;
use Tetranyble\Kinship\Tests\ResolverWorkspacePackageTestCase;

class CustomWorkspaceResolverTest extends ResolverWorkspacePackageTestCase
{
    use RefreshDatabase;

    public function test_explicit_workspace_mode_can_use_a_request_or_domain_resolver(): void
    {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $role = Role::query()->create([
            'name' => 'admin',
            'guard_name' => 'web',
            'workspace_id' => 'workspace-from-request',
        ]);

        $user->assignRoles($role);

        $this->assertTrue($user->hasRole('admin'));
    }
}
