<?php

namespace App\Services;

use App\Models\BusinessCalendar;
use App\Models\SlaPolicy;
use App\Services\Tickets\SlaService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class CustomerSlaOverviewService
{
    public function __construct(private readonly SlaService $sla) {}

    /** @return array<string, mixed>|null */
    public function summary(): ?array
    {
        $policy = SlaPolicy::query()->with(['targets.priority' => fn ($query) => $query->where('is_active', true)->whereNull('deleted_at')])->first();
        $calendar = BusinessCalendar::query()->with('windows')->first();

        if ($policy === null || $calendar === null) {
            return null;
        }

        $weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $windows = $calendar->windows
            ->sortBy('weekday')
            ->map(fn ($window): array => [
                'day' => $weekdays[$window->weekday] ?? 'Day',
                'hours' => CarbonImmutable::parse($window->opens_at)->format('g:i A').'–'.CarbonImmutable::parse($window->closes_at)->format('g:i A'),
            ])
            ->values()
            ->all();
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $targets = $policy->targets
            ->filter(fn ($target): bool => $target->priority !== null)
            ->sortBy(fn ($target): int => $target->priority->sort_order)
            ->map(function ($target) use ($calendar, $now): array {
                try {
                    $dueAt = $this->sla->addBusinessSeconds($calendar, $now, (int) $target->target_seconds);
                } catch (ValidationException) {
                    $dueAt = null;
                }

                return [
                    'priority_id' => $target->priority_id,
                    'priority' => $target->priority->name,
                    'duration' => $this->formatDuration((int) $target->target_seconds),
                    'due_at' => $dueAt?->toIso8601String(),
                    'due_label' => $dueAt?->timezone($calendar->timezone)->format('D, d M Y · h:i A'),
                ];
            })
            ->values()
            ->all();

        return ['timezone' => $calendar->timezone, 'windows' => $windows, 'targets' => $targets, 'availability' => $this->availability($calendar, $now), 'generated_at' => $now->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function availability(BusinessCalendar $calendar, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->startOfSecond();
        $local = $now->timezone($calendar->timezone);
        $date = $local->toDateString();
        $holiday = $calendar->holidays()->whereDate('holiday_date', $date)->value('name');
        $window = $calendar->windows->firstWhere('weekday', $local->isoWeekday());
        $opens = $window ? CarbonImmutable::parse($date.' '.$window->opens_at, $calendar->timezone) : null;
        $closes = $window ? CarbonImmutable::parse($date.' '.$window->closes_at, $calendar->timezone) : null;
        $open = $holiday === null && $opens !== null && $local->greaterThanOrEqualTo($opens) && $local->lessThan($closes);
        $state = $open ? 'open' : ($holiday !== null ? 'holiday' : ($window === null ? 'closed_day' : 'outside_hours'));
        try {
            $nextOpening = $open ? $now : $this->sla->addBusinessSeconds($calendar, $now, 1)->subSecond();
        } catch (ValidationException) {
            $nextOpening = null;
            $state = 'unavailable';
        }
        $message = match ($state) {
            'open' => 'Support is open now',
            'holiday' => 'Support is closed for a holiday'.($holiday ? ': '.$holiday : ''),
            'closed_day' => 'Today is a non-working day',
            'outside_hours' => 'You are outside support hours',
            default => 'Support hours are temporarily unavailable',
        };

        return [
            'state' => $state, 'is_open' => $open, 'message' => $message,
            'timezone' => $calendar->timezone, 'holiday_name' => $holiday,
            'next_open_at' => $nextOpening?->toIso8601String(),
            'next_open_label' => $nextOpening?->timezone($calendar->timezone)->format('D, d M Y · h:i A'),
        ];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds >= 3600 && $seconds % 3600 === 0) {
            $hours = intdiv($seconds, 3600);

            return $hours.' business '.($hours === 1 ? 'hour' : 'hours');
        }

        $minutes = (int) round($seconds / 60);

        return $minutes.' business '.($minutes === 1 ? 'minute' : 'minutes');
    }
}
