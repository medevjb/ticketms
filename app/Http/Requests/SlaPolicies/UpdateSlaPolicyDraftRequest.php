<?php

namespace App\Http\Requests\SlaPolicies;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSlaPolicyDraftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('sla.manage') ?? false;
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
            'calendar_version_id' => ['required', 'integer', 'exists:calendar_versions,id'],
            'warning_percent' => ['required', 'integer', 'between:1,99'],
            'targets' => ['required', 'array', 'min:1', 'max:100'],
            'targets.*' => ['required', 'array:priority_id,target_minutes'],
            'targets.*.priority_id' => ['required', 'integer', 'distinct', 'exists:priorities,id'],
            'targets.*.target_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
        ];
    }

    /** @return list<array{priority_id: int, target_seconds: int}> */
    public function targetData(): array
    {
        $result = [];
        foreach ((array) $this->validated('targets') as $target) {
            if (is_array($target) && isset($target['target_minutes'])) {
                $result[] = [
                    'priority_id' => (int) $target['priority_id'],
                    'target_seconds' => (int) $target['target_minutes'] * 60,
                ];
            }
        }

        return $result;
    }
}
