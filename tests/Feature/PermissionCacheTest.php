<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Permissions\DatabasePermissionGrantSource;
use Tetranyble\Kinship\Tests\Fixtures\TeamPermissionGrantSource;
use Tetranyble\Kinship\Tests\Fixtures\User;
use Tetranyble\Kinship\Tests\PackageTestCase;

class PermissionCacheTest extends PackageTestCase
{
    use RefreshDatabase;

    public function test_a_cold_check_uses_one_grant_query_and_later_requests_use_laravel_cache(): void
    {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $role = Role::query()->create(['name' => 'reviewer']);
        $rolePermission = $this->permission('invoice.view');
        $directPermission = $this->permission('invoice.export');
        $role->givePermissionTo($rolePermission);
        $user->assignRoles($role)->assignPermissions($directPermission);

        $cold = User::query()->findOrFail($user->getKey());
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertTrue($cold->hasAllPermissions('invoice.view', 'invoice.export'));
        $this->assertCount(1, DB::getQueryLog());

        $warm = User::query()->findOrFail($user->getKey());
        DB::flushQueryLog();

        $this->assertTrue($warm->hasAllPermissions('invoice.view', 'invoice.export'));
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_package_mutations_invalidate_cached_allow_and_deny_results(): void
    {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $role = Role::query()->create(['name' => 'reviewer']);
        $permission = $this->permission('invoice.approve');
        $user->assignRoles($role);

        $this->assertFalse(User::query()->findOrFail($user->getKey())->hasPermission('invoice.approve'));

        $role->givePermissionTo($permission);
        $this->assertTrue(User::query()->findOrFail($user->getKey())->hasPermission('invoice.approve'));

        $role->revokePermissionTo($permission);
        $this->assertFalse(User::query()->findOrFail($user->getKey())->hasPermission('invoice.approve'));

        $user->assignPermissions($permission);
        $this->assertTrue(User::query()->findOrFail($user->getKey())->hasPermission('invoice.approve'));

        $user->removePermissions($permission);
        $this->assertFalse(User::query()->findOrFail($user->getKey())->hasPermission('invoice.approve'));
    }

    public function test_reverse_permission_role_mutations_invalidate_cached_results(): void
    {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $role = Role::query()->create(['name' => 'reviewer']);
        $permission = $this->permission('invoice.approve');
        $user->assignRoles($role);

        $this->assertFalse($user->hasPermission('invoice.approve'));

        $permission->assignRoles($role);
        $this->assertTrue($user->hasPermission('invoice.approve'));

        $permission->syncRoles();
        $this->assertFalse($user->hasPermission('invoice.approve'));
    }

    public function test_direct_eloquent_pivot_mutations_invalidate_cached_results(): void
    {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $role = Role::query()->create(['name' => 'reviewer']);
        $permission = $this->permission('invoice.export');
        $user->assignRoles($role);

        $this->assertFalse($user->hasPermission('invoice.export'));

        $role->permissions()->attach($permission->id);
        $this->assertTrue($user->hasPermission('invoice.export'));

        $role->permissions()->detach($permission->id);
        $this->assertFalse($user->hasPermission('invoice.export'));
    }

    public function test_large_permission_sets_are_flattened_once_and_cached_as_names(): void
    {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $now = now();
        $rows = [];

        for ($index = 0; $index < 4000; $index++) {
            $rows[] = [
                'name' => "resource_{$index}.view",
                'label' => "Resource {$index} View",
                'group' => "resource_{$index}",
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('permissions')->insert($chunk);
        }

        $permissionIds = DB::table('permissions')->pluck('id');
        foreach ($permissionIds->chunk(100) as $ids) {
            DB::table('permission_user')->insert($ids->map(fn (mixed $id): array => [
                'permission_id' => $id,
                'user_id' => $user->getKey(),
                'workspace_id' => '__kinship_global__',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        $cold = User::query()->findOrFail($user->getKey());
        $this->assertCount(4000, $cold->allPermissionNames());

        $warm = User::query()->findOrFail($user->getKey());
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertTrue($warm->hasPermission('resource_3999.view'));
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_an_application_can_union_a_team_grant_source_without_package_team_schema(): void
    {
        Schema::create('team_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('user_id');
        });
        Schema::create('permission_team', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('permission_id');
        });
        config(['kinship.permission_sources' => [
            DatabasePermissionGrantSource::class,
            TeamPermissionGrantSource::class,
        ]]);

        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $permission = $this->permission('settlement.release');
        DB::table('team_user')->insert(['team_id' => 10, 'user_id' => $user->getKey()]);
        DB::table('permission_team')->insert(['team_id' => 10, 'permission_id' => $permission->getKey()]);
        $user->forgetKinshipAuthorizationCache();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertTrue($user->hasPermission('settlement.release'));
        $this->assertCount(1, DB::getQueryLog());
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
