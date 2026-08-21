<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Kinship\Support\WorkspaceMode;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();

            if (WorkspaceMode::enabled()) {
                $table->unsignedBigInteger(WorkspaceMode::subjectForeignKey())->nullable();
            }

            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('mfa_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
