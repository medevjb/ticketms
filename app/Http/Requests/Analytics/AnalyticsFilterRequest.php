<?php

namespace App\Http\Requests\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AnalyticsFilterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('analytics.view') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            $from = $this->input('from');
            $to = $this->input('to');
            if (is_string($from) && is_string($to)
                && CarbonImmutable::parse($to, 'Asia/Dhaka')->diffInDays(CarbonImmutable::parse($from, 'Asia/Dhaka')) > 365) {
                $validator->errors()->add('to', 'Choose a date range of 366 days or less.');
            }
        }];
    }
}
