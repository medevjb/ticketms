<?php

namespace App\Http\Requests\SlaPolicies;

use App\Models\Priority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSlaPolicyRequest extends FormRequest
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
            'warning_percent' => ['required', 'integer', 'between:1,99'],
            'targets' => ['required', 'array', 'min:1', 'max:100'],
            'targets.*' => ['required', 'array:priority_id,target_minutes'],
            'targets.*.priority_id' => ['required', 'integer', 'distinct', 'exists:priorities,id'],
            'targets.*.target_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['targets', 'targets.*.priority_id', 'targets.*.target_minutes'])) {
                return;
            }

            $submittedTargets = (array) $this->input('targets', []);
            $activePriorityIds = Priority::query()->active()->pluck('id')->all();

            foreach ($activePriorityIds as $priorityId) {
                $matchingIndex = collect($submittedTargets)->search(
                    fn ($target): bool => is_array($target) && (int) ($target['priority_id'] ?? 0) === (int) $priorityId,
                );

                if ($matchingIndex === false) {
                    $validator->errors()->add('targets', 'Every active priority must have a resolution target.');

                    continue;
                }

                if (blank($submittedTargets[$matchingIndex]['target_minutes'] ?? null)) {
                    $validator->errors()->add("targets.{$matchingIndex}.target_minutes", 'A resolution target is required for every active priority.');
                }
            }
        }];
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
