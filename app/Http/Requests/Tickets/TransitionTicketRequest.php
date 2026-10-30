<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketTransition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tickets.view') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $transition = $this->input('transition');
        $needsPublicMessage = in_array($transition, [TicketTransition::Wait->value, TicketTransition::Resolve->value, TicketTransition::Reopen->value], true);
        $needsReason = $transition === TicketTransition::Reopen->value;

        return [
            'transition' => ['required', Rule::enum(TicketTransition::class)],
            'operation_key' => ['required', 'uuid'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'public_message' => [Rule::requiredIf($needsPublicMessage), 'nullable', 'string', 'min:5', 'max:20000'],
            'reason' => [Rule::requiredIf($needsReason), 'nullable', 'string', 'min:5', 'max:500'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['required', 'file', 'max:10240', 'extensions:pdf,jpg,jpeg,png,txt,csv'],
        ];
    }
}
