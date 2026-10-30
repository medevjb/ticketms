<?php

namespace App\Http\Requests\Tickets;

use App\Enums\MessageVisibility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tickets.view') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'visibility' => ['required', Rule::enum(MessageVisibility::class)],
            'body' => ['required', 'string', 'min:1', 'max:20000'],
            'reopen' => ['sometimes', 'boolean'],
            'reason' => ['required_if:reopen,1', 'nullable', 'string', 'min:5', 'max:500'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['required', 'file', 'max:10240', 'extensions:pdf,jpg,jpeg,png,txt,csv'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->has('body') && trim((string) $this->input('body')) === '') {
                $validator->errors()->add('body', 'A message cannot contain only whitespace.');
            }
        }];
    }
}
