<?php

namespace App\Http\Requests\Imports;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTicketImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('imports.manage') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:64', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._:-]*\z/'],
            'file' => ['required', 'file', 'max:102400', 'extensions:csv', 'mimes:csv,txt'],
        ];
    }
}
