<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ModerateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('messages.moderate') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'action' => ['required', 'in:hide,unhide,archive,restore'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'expected_updated_at' => ['required', 'date'],
        ];
    }
}
