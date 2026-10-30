<?php

namespace App\Http\Requests\Priorities;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StorePriorityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('priorities.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $slug = trim((string) $this->input('slug', ''));
        $this->merge(['slug' => Str::slug($slug !== '' ? $slug : (string) $this->input('name', ''))]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'slug' => ['required', 'string', 'max:30', 'alpha_dash:ascii', Rule::unique('priorities', 'slug')],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
