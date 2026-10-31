<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'is_archived' => $this->trashed(),
            'email_verified' => $this->email_verified_at !== null,
            'roles' => $this->whenLoaded('roles', fn (): array => $this->roles->pluck('name')->all()),
            'ticket_count' => $this->whenHas('owned_tickets_count'),
            'active_ticket_count' => $this->whenHas('active_tickets_count'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
