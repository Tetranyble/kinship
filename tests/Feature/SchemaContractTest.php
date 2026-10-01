<?php

namespace Tetranyble\Kinship\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Kinship\Tests\WorkspacePackageTestCase;

class SchemaContractTest extends WorkspacePackageTestCase
{
    use RefreshDatabase;

    public function test_default_role_table_has_authorization_critical_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('roles', [
            'id',
            'name',
            'workspace_id',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
        $this->assertFalse(Schema::hasColumn('roles', 'guard_name'));
    }

    public function test_default_permission_table_has_authorization_critical_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('permissions', [
            'id',
            'name',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
        $this->assertFalse(Schema::hasColumn('permissions', 'guard_name'));
    }

    public function test_default_user_grant_pivots_have_required_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('role_user', [
            'role_id',
            'user_id',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('permission_user', [
            'permission_id',
            'user_id',
            'workspace_id',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('permission_role', [
            'permission_id',
            'role_id',
            'created_at',
            'updated_at',
        ]));
    }
}
