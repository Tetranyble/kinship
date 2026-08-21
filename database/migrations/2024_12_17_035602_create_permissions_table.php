<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Kinship\Contracts\GuardResolver;

return new class extends Migration
{
    public function up(): void
    {
        $defaultGuard = app(GuardResolver::class)->resolve();

        Schema::create((string) config('kinship.tables.permissions', 'permissions'), function (Blueprint $table) use ($defaultGuard): void {
            $table->id();
            $table->string('name');
            $table->string('label')->nullable();
            $table->string('group')->nullable()->index();
            $table->string('guard_name')->default($defaultGuard);
            $table->unique(['name', 'guard_name']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('kinship.tables.permissions', 'permissions'));
    }
};
