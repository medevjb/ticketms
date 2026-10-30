<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_profiles', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unique('user_id');
            $table->boolean('auto_assignment_enabled')->default(false);
            $table->dateTime('last_auto_assigned_at', 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes('deleted_at', 6);
            $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['auto_assignment_enabled', 'deleted_at', 'last_auto_assigned_at', 'user_id'], 'agent_assignment_idx');
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes('deleted_at', 6);
            $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::create('priorities', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->string('name', 50);
            $table->string('slug', 30)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes('deleted_at', 6);
            $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::create('calendar_versions', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->unsignedInteger('version_no')->unique();
            $table->string('name', 100);
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->string('publication_state', 20)->default('draft');
            $table->dateTime('published_at', 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes('deleted_at', 6);
            $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::create('calendar_windows', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->foreignId('calendar_version_id')->constrained('calendar_versions')->restrictOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->unique(['calendar_version_id', 'weekday'], 'calendar_window_day_uq');
        });

        Schema::create('calendar_holidays', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->foreignId('calendar_version_id')->constrained('calendar_versions')->restrictOnDelete();
            $table->date('holiday_date');
            $table->string('name', 100);
            $table->unique(['calendar_version_id', 'holiday_date'], 'calendar_holiday_date_uq');
        });

        Schema::create('sla_policies', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->unsignedInteger('version_no')->unique();
            $table->string('name', 100);
            $table->foreignId('calendar_version_id')->constrained('calendar_versions')->restrictOnDelete();
            $table->unsignedTinyInteger('warning_percent')->default(80);
            $table->string('publication_state', 20)->default('draft');
            $table->dateTime('published_at', 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes('deleted_at', 6);
            $table->timestamps(6);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['id', 'calendar_version_id'], 'sla_policy_calendar_uq');
        });

        Schema::create('sla_policy_targets', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->foreignId('sla_policy_id')->constrained('sla_policies')->restrictOnDelete();
            $table->foreignId('priority_id')->constrained('priorities')->restrictOnDelete();
            $table->unsignedInteger('target_seconds');
            $table->unique(['sla_policy_id', 'priority_id'], 'sla_policy_priority_uq');
            $table->unique(['sla_policy_id', 'priority_id', 'target_seconds'], 'sla_policy_target_snapshot_uq');
        });

        Schema::create('ticketing_settings', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('current_sla_policy_id')->nullable()->constrained('sla_policies')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestamps(6);
        });

        DB::table('ticketing_settings')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ticketing_settings');
        Schema::dropIfExists('sla_policy_targets');
        Schema::dropIfExists('sla_policies');
        Schema::dropIfExists('calendar_holidays');
        Schema::dropIfExists('calendar_windows');
        Schema::dropIfExists('calendar_versions');
        Schema::dropIfExists('priorities');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('agent_profiles');
    }
};
