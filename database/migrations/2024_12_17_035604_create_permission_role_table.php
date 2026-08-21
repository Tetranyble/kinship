<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('kinship.tables.permission_role', 'permission_role'), function (Blueprint $table): void {
            $permissionKey = (string) config('kinship.columns.permission_foreign_key', 'permission_id');
            $roleKey = (string) config('kinship.columns.role_foreign_key', 'role_id');

            $table->unsignedBigInteger($permissionKey);
            $table->unsignedBigInteger($roleKey);
            $table->primary([$permissionKey, $roleKey]);
            $table->index($roleKey);
            $table->foreign($permissionKey)
                ->references('id')
                ->on((string) config('kinship.tables.permissions', 'permissions'))
                ->cascadeOnDelete();
            $table->foreign($roleKey)
                ->references('id')
                ->on((string) config('kinship.tables.roles', 'roles'))
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('kinship.tables.permission_role', 'permission_role'));
    }
};
