<?php

namespace App\Http\Requests\Calendars;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCalendarDraftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('calendars.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'windows' => ['required', 'array', 'min:1', 'max:7'],
            'windows.*' => ['required', 'array:weekday,opens_at,closes_at'],
            'windows.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'windows.*.opens_at' => ['required', 'date_format:H:i'],
            'windows.*.closes_at' => ['required', 'date_format:H:i'],
            'holidays' => ['nullable', 'array', 'max:500'],
            'holidays.*' => ['required', 'array:holiday_date,name'],
            'holidays.*.holiday_date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'holidays.*.name' => ['required', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('windows', []) as $index => $window) {
                if (! is_array($window) || ! is_string($window['opens_at'] ?? null) || ! is_string($window['closes_at'] ?? null)) {
                    continue;
                }

                if ($window['opens_at'] >= $window['closes_at']) {
                    $validator->errors()->add("windows.{$index}.closes_at", 'Closing time must be later than opening time.');
                }
            }
        });
    }

    /** @return list<array{weekday: int, opens_at: string, closes_at: string}> */
    public function windowData(): array
    {
        $result = [];
        foreach ((array) $this->validated('windows') as $window) {
            if (is_array($window)) {
                $result[] = [
                    'weekday' => (int) $window['weekday'],
                    'opens_at' => (string) $window['opens_at'],
                    'closes_at' => (string) $window['closes_at'],
                ];
            }
        }

        return $result;
    }

    /** @return list<array{holiday_date: string, name: string}> */
    public function holidayData(): array
    {
        $result = [];
        foreach ((array) $this->validated('holidays', []) as $holiday) {
            if (is_array($holiday)) {
                $result[] = ['holiday_date' => (string) $holiday['holiday_date'], 'name' => (string) $holiday['name']];
            }
        }

        return $result;
    }
}
