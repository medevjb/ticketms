<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketPriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tickets.change_priority') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'priority_id' => ['required', 'integer', Rule::exists('priorities', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))],
        ];
    }
}
