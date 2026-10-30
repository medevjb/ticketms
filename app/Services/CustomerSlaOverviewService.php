<?php

namespace App\Services;

use App\Enums\PublicationState;
use App\Models\SlaPolicy;
use App\Models\TicketingSetting;
use Carbon\CarbonImmutable;

class CustomerSlaOverviewService
{
    /** @return array{timezone: string, windows: list<array{day: string, hours: string}>, targets: list<array{priority: string, duration: string}>}|null */
    public function summary(): ?array
    {
        $policyId = TicketingSetting::query()->find(1)?->current_sla_policy_id;

        if ($policyId === null) {
            return null;
        }

        $policy = SlaPolicy::query()
            ->whereKey($policyId)
            ->where('publication_state', PublicationState::Published)
            ->where('is_active', true)
            ->with([
                'calendarVersion' => fn ($query) => $query
                    ->where('publication_state', PublicationState::Published)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->with('windows'),
                'targets.priority' => fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'),
            ])
            ->first();

        if ($policy?->calendarVersion === null) {
            return null;
        }

        $calendar = $policy->calendarVersion;
        $weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $windows = $calendar->windows
            ->sortBy('weekday')
            ->map(fn ($window): array => [
                'day' => $weekdays[$window->weekday] ?? 'Day',
                'hours' => CarbonImmutable::parse($window->opens_at)->format('g:i A').'–'.CarbonImmutable::parse($window->closes_at)->format('g:i A'),
            ])
            ->values()
            ->all();
        $targets = $policy->targets
            ->filter(fn ($target): bool => $target->priority !== null)
            ->sortBy(fn ($target): int => $target->priority->sort_order)
            ->map(fn ($target): array => [
                'priority' => $target->priority->name,
                'duration' => $this->formatDuration((int) $target->target_seconds),
            ])
            ->values()
            ->all();

        return ['timezone' => $calendar->timezone, 'windows' => $windows, 'targets' => $targets];
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
