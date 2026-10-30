<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tickets.create') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $user = $this->user();
        $isAdministrator = $user !== null && in_array('admin', $user->tyroRoleSlugs(), true);

        return [
            'operation_key' => ['required', 'uuid'],
            'customer_id' => [
                Rule::requiredIf($isAdministrator),
                Rule::prohibitedIf(! $isAdministrator),
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at')->whereNotNull('email_verified_at')),
            ],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))],
            'priority_id' => ['required', 'integer', Rule::exists('priorities', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['required', 'file', 'max:10240', 'extensions:pdf,jpg,jpeg,png,txt,csv'],
        ];
    }
}
