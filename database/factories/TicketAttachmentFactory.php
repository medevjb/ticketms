<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TicketAttachment> */
class TicketAttachmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'uploaded_by' => User::factory(),
            'disk' => 'local',
            'path' => 'tickets/'.Str::uuid().'.txt',
            'original_name' => 'details.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 10,
            'sha256' => hash('sha256', 'attachment'),
        ];
    }
}
