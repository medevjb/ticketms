<?php

namespace App\Models;

use App\Enums\SlaIntervalKind;
use Carbon\CarbonImmutable;
use Database\Factories\TicketSlaIntervalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property SlaIntervalKind $kind
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int $resolution_cycle
 */
class TicketSlaInterval extends Model
{
    /** @use HasFactory<TicketSlaIntervalFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $table = 'ticket_sla_intervals';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => SlaIntervalKind::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'resolution_cycle' => 'integer',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by')->withTrashed();
    }
}
