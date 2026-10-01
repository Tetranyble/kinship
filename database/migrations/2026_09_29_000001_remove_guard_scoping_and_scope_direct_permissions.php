<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

return new class extends Migration
{
    public function up(): void
    {
        $roles = (string) config('kinship.tables.roles', 'roles');
        $permissions = (string) config('kinship.tables.permissions', 'permissions');
        $permissionUser = (string) config('kinship.tables.permission_user', 'permission_user');
        $permissionKey = (string) config('kinship.columns.permission_foreign_key', 'permission_id');
        $userKey = (string) config('kinship.columns.user_foreign_key', 'user_id');
        $workspace = app(WorkspaceConfiguration::class);
        $scopeKey = $workspace->roleForeignKey();

        $legacyGuards = collect();
        if (Schema::hasColumn($roles, 'guard_name')) {
            $legacyGuards = $legacyGuards->merge(DB::table($roles)->distinct()->pluck('guard_name'));
        }
        if (Schema::hasColumn($permissions, 'guard_name')) {
            $legacyGuards = $legacyGuards->merge(DB::table($permissions)->distinct()->pluck('guard_name'));
        }
        $legacyGuards = $legacyGuards
            ->filter(fn (mixed $guard): bool => is_string($guard) && trim($guard) !== '')
            ->map(fn (string $guard): string => trim($guard))
            ->unique()
            ->values();

        if ($legacyGuards->count() > 1) {
            throw new RuntimeException(
                'Kinship cannot automatically merge multiple legacy authorization guards ['
                .$legacyGuards->implode(', ')
                .']. Consolidate guard-specific roles, permissions, and assignments before upgrading.',
            );
        }

        if (Schema::hasColumn($roles, 'guard_name')) {
            $duplicateRole = DB::table($roles)
                ->select(['name', $scopeKey])
                ->groupBy('name', $scopeKey)
                ->havingRaw('COUNT(*) > 1')
                ->first();

            if ($duplicateRole !== null) {
                throw new RuntimeException('Kinship cannot remove guard scoping while duplicate role names exist in the same workspace. Consolidate those roles before upgrading.');
            }

            Schema::table($roles, function (Blueprint $table) use ($scopeKey): void {
                $table->dropUnique(['name', 'guard_name', $scopeKey]);
                $table->dropColumn('guard_name');
            });
            Schema::table($roles, fn (Blueprint $table) => $table->unique(['name', $scopeKey]));
        }

        if (Schema::hasColumn($permissions, 'guard_name')) {
            $duplicatePermission = DB::table($permissions)
                ->select('name')
                ->groupBy('name')
                ->havingRaw('COUNT(*) > 1')
                ->first();

            if ($duplicatePermission !== null) {
                throw new RuntimeException('Kinship cannot remove guard scoping while duplicate permission names exist across guards. Consolidate those permissions before upgrading.');
            }

            Schema::table($permissions, function (Blueprint $table): void {
                $table->dropUnique(['name', 'guard_name']);
                $table->dropColumn('guard_name');
            });
            Schema::table($permissions, fn (Blueprint $table) => $table->unique('name'));
        }

        if (! Schema::hasColumn($permissionUser, $scopeKey)) {
            Schema::table($permissionUser, function (Blueprint $table) use ($permissionKey, $userKey, $scopeKey, $workspace): void {
                $table->dropPrimary([$permissionKey, $userKey]);
                $table->string($scopeKey, 191)->default($workspace->globalScopeValue);
                $table->primary([$permissionKey, $userKey, $scopeKey]);
                $table->index([$userKey, $scopeKey]);
            });
        }
    }

    public function down(): void
    {
        // Authorization guard scoping and cross-workspace direct grants are intentionally
        // not reconstructed automatically because doing so would recreate unsafe semantics.
    }
};
