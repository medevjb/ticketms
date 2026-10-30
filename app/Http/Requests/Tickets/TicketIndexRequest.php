<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('tickets.view') && $user->can('tickets.search');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $user = $this->user();
        $isAdministrator = $user !== null && in_array('admin', $user->tyroRoleSlugs(), true);

        return [
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', Rule::in(['active', 'all', 'resolved', 'closed', 'archived'])],
            'priority_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1', Rule::prohibitedIf(! $isAdministrator)],
            'agent_id' => ['nullable', 'integer', 'min:1', Rule::prohibitedIf(! $isAdministrator)],
            'sla_state' => ['nullable', 'string', Rule::in(['within', 'approaching', 'breached', 'paused', 'stopped'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort' => ['nullable', 'string', Rule::in(['created_at', 'updated_at', 'number', 'sla_due_at'])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([15, 30, 50])],
        ];
    }
}
