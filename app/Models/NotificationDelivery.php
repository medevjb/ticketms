<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $recipient_id
 * @property NotificationChannel $channel
 * @property DeliveryStatus $delivery_status
 * @property int $attempts
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $lease_until
 * @property string $notification_uuid
 * @property array<string, mixed> $payload
 */
class NotificationDelivery extends Model
{
    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $table = 'notification_deliveries';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'delivery_status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<ActivityEntry, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(ActivityEntry::class, 'activity_entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id')->withTrashed();
    }
}
