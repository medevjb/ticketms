<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tickets.update_content') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
        ];
    }
}
