<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->string('source', 64)->collation(Schema::getConnection()->getDriverName() === 'mysql' ? 'utf8mb4_0900_as_cs' : 'BINARY');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('disk', 30)->default('local');
            $table->string('path', 512);
            $table->char('checksum', 64);
            $table->string('processing_status', 30)->default('queued');
            $table->unsignedBigInteger('processed_rows')->default(0);
            $table->unsignedBigInteger('created_rows')->default(0);
            $table->unsignedBigInteger('invalid_rows')->default(0);
            $table->unsignedBigInteger('duplicate_rows')->default(0);
            $table->unsignedBigInteger('last_row_number')->default(0);
            $table->unsignedBigInteger('last_byte_offset')->default(0);
            $table->string('error_report_path', 512)->nullable();
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes('deleted_at', 6);
            $table->timestamps(6);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['id', 'source'], 'import_run_source_uq');
            $table->index(['uploaded_by', 'created_at', 'id'], 'import_uploader_list_idx');
        });

        Schema::create('import_rows', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->foreignId('import_run_id')->constrained('import_runs')->restrictOnDelete();
            $table->unsignedBigInteger('row_number');
            $table->string('external_id', 128)->nullable()->collation(Schema::getConnection()->getDriverName() === 'mysql' ? 'utf8mb4_0900_as_cs' : 'BINARY');
            $table->string('result', 20);
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->restrictOnDelete();
            $table->json('error_codes')->nullable();
            $table->dateTime('processed_at', 6);
            $table->unique(['import_run_id', 'row_number'], 'import_run_row_uq');
            $table->index(['import_run_id', 'result', 'row_number'], 'import_result_list_idx');
        });

        Schema::create('ticket_external_references', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->string('source', 64)->collation(Schema::getConnection()->getDriverName() === 'mysql' ? 'utf8mb4_0900_as_cs' : 'BINARY');
            $table->string('external_id', 128)->collation(Schema::getConnection()->getDriverName() === 'mysql' ? 'utf8mb4_0900_as_cs' : 'BINARY');
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->unique('ticket_id');
            $table->unsignedBigInteger('import_run_id');
            $table->foreign(['import_run_id', 'source'], 'external_reference_import_source_fk')->references(['id', 'source'])->on('import_runs')->restrictOnDelete();
            $table->unique(['source', 'external_id'], 'external_source_identity_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_external_references');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_runs');
    }
};
