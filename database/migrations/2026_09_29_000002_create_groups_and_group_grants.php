<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

return new class extends Migration
{
    public function up(): void
    {
        $groups = (string) config('kinship.tables.groups', 'groups');
        $groupUser = (string) config('kinship.tables.group_user', 'group_user');
        $groupRole = (string) config('kinship.tables.group_role', 'group_role');
        $groupPermission = (string) config('kinship.tables.group_permission', 'group_permission');
        $roles = (string) config('kinship.tables.roles', 'roles');
        $permissions = (string) config('kinship.tables.permissions', 'permissions');
        $groupKey = (string) config('kinship.columns.group_foreign_key', 'group_id');
        $userKey = (string) config('kinship.columns.user_foreign_key', 'user_id');
        $roleKey = (string) config('kinship.columns.role_foreign_key', 'role_id');
        $permissionKey = (string) config('kinship.columns.permission_foreign_key', 'permission_id');
        $workspace = app(WorkspaceConfiguration::class);
        $scopeKey = $workspace->groupForeignKey();

        Schema::create($groups, function (Blueprint $table) use ($workspace, $scopeKey): void {
            $table->id();
            $table->string('name');
            $table->string('label')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->string($scopeKey, 191)->default($workspace->globalScopeValue)->index();
            $table->unique(['name', $scopeKey]);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create($groupUser, function (Blueprint $table) use ($groups, $groupKey, $userKey): void {
            $table->unsignedBigInteger($groupKey);
            $table->unsignedBigInteger($userKey);
            $table->primary([$groupKey, $userKey]);
            $table->index([$userKey, $groupKey]);
            $table->foreign($groupKey)->references('id')->on($groups)->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create($groupRole, function (Blueprint $table) use ($groups, $roles, $groupKey, $roleKey): void {
            $table->unsignedBigInteger($groupKey);
            $table->unsignedBigInteger($roleKey);
            $table->primary([$groupKey, $roleKey]);
            $table->index($roleKey);
            $table->foreign($groupKey)->references('id')->on($groups)->cascadeOnDelete();
            $table->foreign($roleKey)->references('id')->on($roles)->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create($groupPermission, function (Blueprint $table) use ($groups, $permissions, $groupKey, $permissionKey): void {
            $table->unsignedBigInteger($groupKey);
            $table->unsignedBigInteger($permissionKey);
            $table->primary([$groupKey, $permissionKey]);
            $table->index($permissionKey);
            $table->foreign($groupKey)->references('id')->on($groups)->cascadeOnDelete();
            $table->foreign($permissionKey)->references('id')->on($permissions)->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('kinship.tables.group_permission', 'group_permission'));
        Schema::dropIfExists((string) config('kinship.tables.group_role', 'group_role'));
        Schema::dropIfExists((string) config('kinship.tables.group_user', 'group_user'));
        Schema::dropIfExists((string) config('kinship.tables.groups', 'groups'));
    }
};
