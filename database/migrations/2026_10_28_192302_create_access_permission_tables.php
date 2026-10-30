<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_permissions', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->string('name', 125);
            $table->string('guard_name', 30)->default('web');
            $table->timestamps(6);
            $table->unique(['name', 'guard_name'], 'access_permissions_name_guard_uq');
        });

        Schema::create('access_roles', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->string('name', 125);
            $table->string('guard_name', 30)->default('web');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->softDeletes('deleted_at', 6);
            $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['name', 'guard_name'], 'access_roles_name_guard_uq');
        });

        Schema::create('access_model_has_permissions', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->foreignId('permission_id')->constrained('access_permissions')->restrictOnDelete();
            $table->string('model_type', 125);
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'access_mhp_model_idx');
            $table->primary(['permission_id', 'model_id', 'model_type'], 'access_mhp_pk');
        });

        Schema::create('access_model_has_roles', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->foreignId('role_id')->constrained('access_roles')->restrictOnDelete();
            $table->string('model_type', 125);
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'access_mhr_model_idx');
            $table->primary(['role_id', 'model_id', 'model_type'], 'access_mhr_pk');
        });

        Schema::create('access_role_has_permissions', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->foreignId('permission_id')->constrained('access_permissions')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('access_roles')->restrictOnDelete();
            $table->primary(['permission_id', 'role_id'], 'access_rhp_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_role_has_permissions');
        Schema::dropIfExists('access_model_has_roles');
        Schema::dropIfExists('access_model_has_permissions');
        Schema::dropIfExists('access_roles');
        Schema::dropIfExists('access_permissions');
    }
};
