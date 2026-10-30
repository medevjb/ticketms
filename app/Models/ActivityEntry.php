<?php

namespace App\Models;

use App\Enums\ActorKind;
use App\Enums\MessageVisibility;
use Database\Factories\ActivityEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property MessageVisibility $visibility
 */
class ActivityEntry extends Model
{
    /** @use HasFactory<ActivityEntryFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $table = 'activity_entries';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'actor_kind' => ActorKind::class,
            'visibility' => MessageVisibility::class,
            'changes' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    /** @return HasMany<NotificationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'activity_entry_id');
    }
}
