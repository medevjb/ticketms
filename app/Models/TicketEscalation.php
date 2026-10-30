<?php

namespace App\Models;

use App\Enums\EscalationStage;
use Database\Factories\TicketEscalationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketEscalation extends Model
{
    /** @use HasFactory<TicketEscalationFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $table = 'ticket_escalations';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stage' => EscalationStage::class,
            'threshold_at' => 'immutable_datetime',
            'detected_at' => 'immutable_datetime',
            'resolution_cycle' => 'integer',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id')->withTrashed();
    }

    /** @return BelongsTo<ActivityEntry, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(ActivityEntry::class, 'activity_entry_id');
    }
}
