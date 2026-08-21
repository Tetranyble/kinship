<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('kinship.tables.role_user', 'role_user'), function (Blueprint $table): void {
            $roleKey = (string) config('kinship.columns.role_foreign_key', 'role_id');
            $userKey = (string) config('kinship.columns.user_foreign_key', 'user_id');

            $table->unsignedBigInteger($userKey);
            $table->unsignedBigInteger($roleKey);
            $table->primary([$userKey, $roleKey]);
            $table->foreign($roleKey)
                ->references('id')
                ->on((string) config('kinship.tables.roles', 'roles'))
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('kinship.tables.role_user', 'role_user'));
    }
};
