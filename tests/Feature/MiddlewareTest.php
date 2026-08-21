<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Tests\Fixtures\User;
use Tetranyble\Kinship\Tests\PackageTestCase;

class MiddlewareTest extends PackageTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startSession();

        Route::middleware('web')->group(function (): void {
            Route::get('/roles', fn (): string => 'OK')->middleware('kinship.role:admin,manager');
            Route::get('/permissions', fn (): string => 'OK')->middleware('kinship.permission:user.view,user.update');
        });

        Route::get('/api-guard', fn (): string => 'API OK')->middleware([
            'auth:api',
            'kinship.permission:report.view',
        ]);
    }

    public function test_role_and_permission_middleware_retain_any_of_semantics(): void
    {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $role = Role::query()->create([
            'name' => 'manager',
            'label' => 'Manager',
            'guard_name' => 'web',
        ]);
        $permission = Permission::query()->create([
            'name' => 'user.update',
            'label' => 'Update users',
            'group' => 'user',
            'guard_name' => 'web',
        ]);
        $user->assignRoles($role)->assignPermissions($permission);

        $this->actingAs($user)
            ->get('/roles')
            ->assertOk()
            ->assertSee('OK');

        $this->get('/permissions')
            ->assertOk()
            ->assertSee('OK');
    }

    public function test_json_denials_use_the_compatible_payload(): void
    {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);

        $this->actingAs($user)
            ->getJson('/permissions')
            ->assertForbidden()
            ->assertJson([
                'status' => false,
                'message' => 'This action is unauthorized.',
            ]);
    }

    public function test_laravel_auth_middleware_selects_the_guard_for_kinship(): void
    {
        config([
            'kinship.guard' => null,
            'auth.guards.api' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ]);
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $role = Role::query()->create([
            'name' => 'api-reporter',
            'label' => 'API Reporter',
            'guard_name' => 'api',
        ]);
        $permission = Permission::query()->create([
            'name' => 'report.view',
            'label' => 'View reports',
            'group' => 'report',
            'guard_name' => 'api',
        ]);
        $role->givePermissionTo($permission);
        $user->allRoles()->attach($role);

        $this->actingAs($user, 'api')
            ->get('/api-guard')
            ->assertOk()
            ->assertSee('API OK');
    }
}
