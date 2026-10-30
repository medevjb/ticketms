<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('visibility', 10)->default('public');
            $table->text('body');
            $table->uuid('operation_key')->unique();
            $table->boolean('is_active')->default(true);
            $table->softDeletes('deleted_at', 6);
            $table->timestamps(6);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['id', 'ticket_id'], 'message_ticket_uq');
            $table->index(['ticket_id', 'deleted_at', 'visibility', 'created_at', 'id'], 'ticket_conversation_idx');
        });

        Schema::create('ticket_attachments', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->unsignedBigInteger('ticket_message_id')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('disk', 30)->default('local');
            $table->string('path', 512)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->boolean('is_active')->default(true);
            $table->softDeletes('deleted_at', 6);
            $table->timestamps(6);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreign(['ticket_message_id', 'ticket_id'], 'attachment_message_ticket_fk')->references(['id', 'ticket_id'])->on('ticket_messages')->restrictOnDelete();
            $table->index(['ticket_id', 'deleted_at', 'id'], 'ticket_attachment_list_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_messages');
    }
};
