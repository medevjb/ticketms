<?php

namespace App\Http\Requests\Access;

use App\Enums\LifecycleBulkAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUserLifecycleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
            'action' => ['required', Rule::enum(LifecycleBulkAction::class)],
            'selected_ids' => ['required', 'array', 'min:1', 'max:100'],
            'selected_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ];
    }
}
