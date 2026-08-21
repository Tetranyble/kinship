<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('kinship.tables.permission_user', 'permission_user'), function (Blueprint $table): void {
            $permissionKey = (string) config('kinship.columns.permission_foreign_key', 'permission_id');
            $userKey = (string) config('kinship.columns.user_foreign_key', 'user_id');

            $table->unsignedBigInteger($permissionKey);
            $table->unsignedBigInteger($userKey);
            $table->primary([$permissionKey, $userKey]);
            $table->index($userKey);
            $table->foreign($permissionKey)
                ->references('id')
                ->on((string) config('kinship.tables.permissions', 'permissions'))
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('kinship.tables.permission_user', 'permission_user'));
    }
};
