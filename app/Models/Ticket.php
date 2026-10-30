<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property TicketStatus $status
 * @property int $customer_id
 * @property int|null $assigned_agent_id
 * @property int $sla_target_seconds
 * @property int $sla_consumed_seconds
 * @property int $resolution_cycle
 * @property int $lock_version
 * @property CarbonImmutable $sla_started_at
 * @property CarbonImmutable|null $sla_due_at
 * @property CarbonImmutable|null $sla_warning_at
 * @property CarbonImmutable|null $sla_paused_at
 * @property CarbonImmutable|null $first_breached_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['subject', 'description'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'tickets';

    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deleted_at' => 'immutable_datetime',
            'status' => TicketStatus::class,
            'sla_target_seconds' => 'integer',
            'sla_consumed_seconds' => 'integer',
            'resolution_cycle' => 'integer',
            'lock_version' => 'integer',
            'sla_started_at' => 'immutable_datetime',
            'sla_due_at' => 'immutable_datetime',
            'sla_warning_at' => 'immutable_datetime',
            'sla_paused_at' => 'immutable_datetime',
            'first_breached_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id')->withTrashed();
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id')->withTrashed();
    }

    /** @return BelongsTo<Priority, $this> */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class, 'priority_id')->withTrashed();
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id')->withTrashed();
    }

    /** @return BelongsTo<CalendarVersion, $this> */
    public function calendarVersion(): BelongsTo
    {
        return $this->belongsTo(CalendarVersion::class, 'calendar_version_id')->withTrashed();
    }

    /** @return HasMany<TicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id');
    }

    /** @return HasMany<TicketAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_id');
    }

    /** @return HasMany<ActivityEntry, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(ActivityEntry::class, 'ticket_id');
    }

    /** @return HasMany<TicketSlaInterval, $this> */
    public function slaIntervals(): HasMany
    {
        return $this->hasMany(TicketSlaInterval::class, 'ticket_id');
    }

    /** @return HasMany<TicketEscalation, $this> */
    public function escalations(): HasMany
    {
        return $this->hasMany(TicketEscalation::class, 'ticket_id');
    }

    /** @return HasOne<TicketExternalReference, $this> */
    public function externalReference(): HasOne
    {
        return $this->hasOne(TicketExternalReference::class, 'ticket_id');
    }
}
