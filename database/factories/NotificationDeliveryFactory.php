<?php

namespace Database\Factories;

use App\Models\ActivityEntry;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<NotificationDelivery> */
class NotificationDeliveryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'activity_entry_id' => ActivityEntry::factory(),
            'recipient_id' => User::factory(),
            'channel' => 'database',
            'delivery_status' => 'pending',
            'available_at' => now(),
            'notification_uuid' => (string) Str::uuid(),
            'payload' => ['schema_version' => 1, 'summary' => 'Ticket updated'],
        ];
    }
}
