<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;
use Tetranyble\Kinship\Tests\Fixtures\WorkspaceUser;
use Tetranyble\Kinship\Tests\ForcedGlobalPackageTestCase;

class ForcedGlobalModeTest extends ForcedGlobalPackageTestCase
{
    use RefreshDatabase;

    public function test_false_override_keeps_a_workspace_capable_model_in_global_mode(): void
    {
        $user = WorkspaceUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $role = Role::query()->create(['name' => 'admin']);

        $this->assertFalse(app(WorkspaceConfiguration::class)->enabledFor($user));

        $user->assignRoles($role);

        $this->assertTrue($user->hasRole('admin'));
    }
}
