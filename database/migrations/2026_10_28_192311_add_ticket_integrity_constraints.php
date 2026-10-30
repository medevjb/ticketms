<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private const CHECKS = [
        'users' => [
            'user_authorization_version_positive' => 'authorization_version > 0',
        ],
        'calendar_versions' => [
            'calendar_version_positive' => 'version_no > 0',
            'calendar_publication_state' => "publication_state IN ('draft', 'published')",
            'calendar_publication_time' => "(publication_state = 'draft' AND published_at IS NULL) OR (publication_state = 'published' AND published_at IS NOT NULL)",
        ],
        'calendar_windows' => [
            'calendar_weekday_range' => 'weekday BETWEEN 1 AND 7',
            'calendar_window_order' => 'closes_at > opens_at',
        ],
        'sla_policies' => [
            'sla_version_positive' => 'version_no > 0',
            'sla_warning_percentage' => 'warning_percent BETWEEN 1 AND 99',
            'sla_publication_state' => "publication_state IN ('draft', 'published')",
            'sla_publication_time' => "(publication_state = 'draft' AND published_at IS NULL) OR (publication_state = 'published' AND published_at IS NOT NULL)",
        ],
        'sla_policy_targets' => [
            'sla_target_positive' => 'target_seconds > 0',
        ],
        'ticketing_settings' => [
            'settings_singleton' => 'id = 1',
            'settings_version_positive' => 'lock_version > 0',
        ],
        'ticket_sequences' => [
            'sequence_year_range' => 'year BETWEEN 1 AND 9999',
        ],
        'command_receipts' => [
            'receipt_subject_pair' => '(subject_type IS NULL AND subject_id IS NULL) OR (subject_type IS NOT NULL AND subject_id IS NOT NULL)',
        ],
        'tickets' => [
            'ticket_status_allowed' => "status IN ('open', 'in_progress', 'waiting_customer', 'resolved', 'closed')",
            'ticket_positive_values' => 'sla_target_seconds > 0 AND resolution_cycle > 0 AND lock_version > 0',
            'ticket_content_nonempty' => 'CHAR_LENGTH(TRIM(subject)) > 0 AND CHAR_LENGTH(TRIM(description)) > 0',
            'ticket_waiting_pause' => "(status = 'waiting_customer' AND sla_paused_at IS NOT NULL) OR (status <> 'waiting_customer' AND sla_paused_at IS NULL)",
            'ticket_sla_projections' => "(status IN ('open', 'in_progress') AND sla_due_at IS NOT NULL AND sla_warning_at IS NOT NULL) OR (status IN ('waiting_customer', 'resolved', 'closed') AND sla_due_at IS NULL AND sla_warning_at IS NULL)",
            'ticket_resolution_dates' => "(status IN ('resolved', 'closed') AND resolved_at IS NOT NULL) OR (status NOT IN ('resolved', 'closed') AND resolved_at IS NULL)",
            'ticket_closure_dates' => "(status = 'closed' AND closed_at IS NOT NULL AND closed_at >= resolved_at) OR (status <> 'closed' AND closed_at IS NULL)",
            'ticket_archive_closed' => "deleted_at IS NULL OR status = 'closed'",
            'ticket_threshold_order' => 'sla_due_at IS NULL OR sla_warning_at <= sla_due_at',
        ],
        'ticket_messages' => [
            'message_visibility_allowed' => "visibility IN ('public', 'internal')",
            'message_body_nonempty' => 'CHAR_LENGTH(TRIM(body)) > 0',
        ],
        'ticket_attachments' => [
            'attachment_positive_size' => 'size_bytes > 0',
        ],
        'activity_entries' => [
            'activity_actor_kind' => "(actor_kind = 'user' AND actor_id IS NOT NULL) OR (actor_kind = 'system' AND actor_id IS NULL)",
            'activity_visibility' => "visibility IN ('public', 'internal')",
            'activity_subject_positive' => 'subject_id > 0',
        ],
        'ticket_sla_intervals' => [
            'sla_interval_kind' => "kind IN ('waiting', 'resolved')",
            'sla_interval_order' => 'ended_at IS NULL OR ended_at >= started_at',
            'sla_interval_cycle_positive' => 'resolution_cycle > 0',
        ],
        'ticket_escalations' => [
            'escalation_stage' => "stage IN ('approaching', 'breached')",
            'escalation_cycle_positive' => 'resolution_cycle > 0',
            'escalation_detection_order' => 'detected_at >= threshold_at',
        ],
        'import_runs' => [
            'import_status' => "processing_status IN ('queued', 'running', 'paused', 'completed', 'completed_with_errors', 'failed', 'cancelled')",
            'import_counter_totals' => 'created_rows + invalid_rows + duplicate_rows = processed_rows',
            'import_archive_terminal' => "deleted_at IS NULL OR processing_status IN ('completed', 'completed_with_errors', 'failed', 'cancelled')",
        ],
        'import_rows' => [
            'import_row_number_positive' => '`row_number` > 0',
            'import_row_result' => "result IN ('created', 'invalid', 'duplicate')",
            'import_created_ticket' => "result <> 'created' OR ticket_id IS NOT NULL",
        ],
        'bulk_operations' => [
            'bulk_item_limit' => 'submitted_count BETWEEN 1 AND 100',
            'bulk_result_count' => 'succeeded_count + failed_count <= submitted_count',
        ],
        'bulk_operation_items' => [
            'bulk_item_result' => "result IN ('pending', 'succeeded', 'failed', 'conflict', 'unavailable')",
        ],
        'notification_deliveries' => [
            'delivery_channel' => "channel IN ('database', 'mail')",
            'delivery_status' => "delivery_status IN ('pending', 'processing', 'sent', 'skipped', 'failed')",
            'delivery_sent_at' => "(delivery_status = 'sent' AND sent_at IS NOT NULL) OR (delivery_status <> 'sent' AND sent_at IS NULL)",
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::CHECKS as $table => $constraints) {
            foreach ($constraints as $name => $expression) {
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
            }
        }

        Schema::table('tickets', function (Blueprint $table): void {
            $table->fullText(['subject', 'description'], 'ticket_content_fulltext');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropFullText('ticket_content_fulltext');
        });

        foreach (array_reverse(self::CHECKS, true) as $table => $constraints) {
            foreach (array_keys($constraints) as $name) {
                DB::statement("ALTER TABLE `{$table}` DROP CHECK `{$name}`");
            }
        }
    }
};
