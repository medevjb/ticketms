<?php

namespace App\Http\Requests\Customer;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && in_array('customer', $this->user()->tyroRoleSlugs(), true);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp',
                'max:2048', 'dimensions:max_width=2048,max_height=2048',
            ],
        ];
    }
}
