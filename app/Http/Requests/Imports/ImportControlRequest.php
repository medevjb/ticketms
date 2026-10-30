<?php

namespace App\Http\Requests\Imports;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('imports.manage') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:pause,resume,cancel,archive,restore'],
        ];
    }
}
