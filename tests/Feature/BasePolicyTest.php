<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetranyble\Kinship\Models\Permission;
use Tetranyble\Kinship\Policies\BasePolicy;
use Tetranyble\Kinship\Tests\Fixtures\User;
use Tetranyble\Kinship\Tests\PackageTestCase;

class SampleRecord extends Model {}

class BasePolicyTest extends PackageTestCase
{
    use RefreshDatabase;

    public function test_policy_derives_a_snake_case_resource_permission(): void
    {
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $permission = Permission::query()->create([
            'name' => 'sample_record.update',
            'label' => 'Update sample records',
            'group' => 'sample_record',
        ]);
        $policy = new class extends BasePolicy
        {
            public function update(mixed $user, Model $record): bool
            {
                return $this->allowsAbility($user, 'update', $record);
            }
        };
        $record = new SampleRecord;

        $this->assertFalse($policy->update($user, $record));

        $user->assignPermissions($permission);

        $this->assertTrue($policy->update($user, $record));
    }
}
