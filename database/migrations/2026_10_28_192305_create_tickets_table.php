<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('priority_id')->constrained('priorities')->restrictOnDelete();
            $table->string('subject', 255);
            $table->text('description');
            $table->string('status', 30)->default('open');
            $table->unsignedBigInteger('sla_policy_id');
            $table->unsignedBigInteger('calendar_version_id');
            $table->unsignedInteger('sla_target_seconds');
            $table->dateTime('sla_started_at', 6);
            $table->dateTime('sla_due_at', 6)->nullable();
            $table->dateTime('sla_warning_at', 6)->nullable();
            $table->dateTime('sla_paused_at', 6)->nullable();
            $table->unsignedBigInteger('sla_consumed_seconds')->default(0);
            $table->dateTime('first_breached_at', 6)->nullable();
            $table->unsignedInteger('resolution_cycle')->default(1);
            $table->dateTime('resolved_at', 6)->nullable();
            $table->dateTime('closed_at', 6)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->softDeletes('deleted_at', 6);
            $table->timestamps(6);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreign(['sla_policy_id', 'calendar_version_id'], 'ticket_policy_calendar_fk')->references(['id', 'calendar_version_id'])->on('sla_policies')->restrictOnDelete();
            $table->foreign(['sla_policy_id', 'priority_id', 'sla_target_seconds'], 'ticket_policy_target_fk')->references(['sla_policy_id', 'priority_id', 'target_seconds'])->on('sla_policy_targets')->restrictOnDelete();
            $table->index(['deleted_at', 'customer_id', 'created_at', 'id'], 'ticket_customer_list_idx');
            $table->index(['deleted_at', 'assigned_agent_id', 'status', 'id'], 'ticket_agent_workload_idx');
            $table->index(['deleted_at', 'status', 'created_at', 'id'], 'ticket_status_list_idx');
            $table->index(['deleted_at', 'status', 'sla_due_at', 'id'], 'ticket_due_scan_idx');
            $table->index(['deleted_at', 'status', 'sla_warning_at', 'id'], 'ticket_warning_scan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
