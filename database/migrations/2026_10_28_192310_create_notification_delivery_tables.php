<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->id();
            $table->foreignId('activity_entry_id')->constrained('activity_entries')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->string('channel', 20);
            $table->string('delivery_status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at', 6);
            $table->dateTime('lease_until', 6)->nullable();
            $table->uuid('notification_uuid');
            $table->json('payload');
            $table->string('last_error_code', 80)->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->unique(['activity_entry_id', 'recipient_id', 'channel'], 'delivery_activity_recipient_channel_uq');
            $table->unique(['notification_uuid', 'channel'], 'delivery_uuid_channel_uq');
            $table->index(['delivery_status', 'available_at', 'id'], 'delivery_pending_idx');
            $table->index(['delivery_status', 'lease_until', 'id'], 'delivery_lease_idx');
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->dateTime('read_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notification_unread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_deliveries');
    }
};
