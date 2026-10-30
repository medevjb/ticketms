<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_entries', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_kind', 20)->default('user');
            $table->string('action', 80);
            $table->string('visibility', 10)->default('internal');
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->uuid('operation_key');
            $table->uuid('correlation_id');
            $table->dateTime('occurred_at', 6);
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->unique(['operation_key', 'action', 'subject_type', 'subject_id'], 'activity_operation_subject_uq');
            $table->unique(['id', 'ticket_id'], 'activity_ticket_uq');
            $table->index(['ticket_id', 'occurred_at', 'id'], 'ticket_activity_list_idx');
            $table->index(['subject_type', 'subject_id', 'occurred_at', 'id'], 'subject_activity_list_idx');
        });

        Schema::create('ticket_sla_intervals', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->string('kind', 20);
            $table->dateTime('started_at', 6);
            $table->dateTime('ended_at', 6)->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('resolution_cycle')->default(1);
            $table->unsignedBigInteger('open_ticket_id')->nullable()->virtualAs('CASE WHEN ended_at IS NULL THEN ticket_id ELSE NULL END');
            $table->unique('open_ticket_id', 'one_open_sla_interval_uq');
            $table->index(['ticket_id', 'started_at', 'id'], 'ticket_sla_interval_idx');
        });

        Schema::create('ticket_escalations', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->unsignedInteger('resolution_cycle');
            $table->string('stage', 20);
            $table->dateTime('threshold_at', 6);
            $table->dateTime('detected_at', 6);
            $table->unsignedBigInteger('activity_entry_id');
            $table->foreign(['activity_entry_id', 'ticket_id'], 'escalation_activity_ticket_fk')->references(['id', 'ticket_id'])->on('activity_entries')->restrictOnDelete();
            $table->unique(['ticket_id', 'resolution_cycle', 'stage'], 'ticket_cycle_stage_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_escalations');
        Schema::dropIfExists('ticket_sla_intervals');
        Schema::dropIfExists('activity_entries');
    }
};
