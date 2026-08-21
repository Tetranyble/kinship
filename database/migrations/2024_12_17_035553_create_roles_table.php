<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Kinship\Contracts\GuardResolver;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = (string) config('kinship.tables.roles', 'roles');
        $workspace = app(WorkspaceConfiguration::class);
        $defaultGuard = app(GuardResolver::class)->resolve();
        $workspaceColumn = $workspace->roleForeignKey();

        Schema::create($tableName, function (Blueprint $table) use ($defaultGuard, $workspace, $workspaceColumn): void {
            $table->id();
            $table->string('name');
            $table->string('label')->nullable();
            $table->integer('order')->default(1);
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('guard_name')->default($defaultGuard);

            $table->string($workspaceColumn, 191)->default($workspace->globalScopeValue)->index();
            $table->unique(['name', 'guard_name', $workspaceColumn]);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('kinship.tables.roles', 'roles'));
    }
};
