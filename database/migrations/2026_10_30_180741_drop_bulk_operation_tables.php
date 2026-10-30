<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bulk_operation_items');
        Schema::dropIfExists('bulk_operations');
    }

    public function down(): void
    {
        Schema::create('bulk_operations', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('module', 60);
            $table->string('action', 60);
            $table->unsignedInteger('submitted_count');
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->dateTime('started_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->json('summary')->nullable();
        });

        Schema::create('bulk_operation_items', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->foreignId('bulk_operation_id')->constrained('bulk_operations')->restrictOnDelete();
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('expected_version')->nullable();
            $table->dateTime('expected_updated_at', 6)->nullable();
            $table->string('result', 20)->default('pending');
            $table->string('error_code', 80)->nullable();
            $table->unique(['bulk_operation_id', 'subject_type', 'subject_id'], 'bulk_operation_subject_uq');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `bulk_operations` ADD CONSTRAINT `bulk_item_limit` CHECK (submitted_count BETWEEN 1 AND 100)');
            DB::statement('ALTER TABLE `bulk_operations` ADD CONSTRAINT `bulk_result_count` CHECK (succeeded_count + failed_count <= submitted_count)');
            DB::statement("ALTER TABLE `bulk_operation_items` ADD CONSTRAINT `bulk_item_result` CHECK (result IN ('pending', 'succeeded', 'failed', 'conflict', 'unavailable'))");
        }
    }
};
