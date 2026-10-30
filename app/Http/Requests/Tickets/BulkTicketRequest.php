<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketBulkAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tickets.bulk') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'action' => ['required', Rule::enum(TicketBulkAction::class)],
            'selected_ids' => ['required', 'array', 'min:1', 'max:100'],
            'selected_ids.*' => ['required', 'integer', 'distinct', 'exists:tickets,id'],
            'expected_versions' => ['required', 'array'],
            'expected_versions.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['selected_ids', 'expected_versions'])) {
                return;
            }

            $selectedIds = array_map('strval', $this->input('selected_ids', []));
            $versionIds = array_map('strval', array_keys($this->input('expected_versions', [])));
            if (array_diff($selectedIds, $versionIds) !== []) {
                $validator->errors()->add('expected_versions', 'Refresh the ticket list and select the records again.');
            }
        }];
    }
}
