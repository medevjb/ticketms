<?php

namespace App\Services\Calendars;

use App\Enums\ActorKind;
use App\Enums\MessageVisibility;
use App\Models\ActivityEntry;
use App\Models\BusinessCalendar;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CalendarService
{
    public function __construct(private readonly EffectivePermissionService $permissions) {}

    public function current(User $actor): BusinessCalendar
    {
        $this->authorizeManagement($actor);

        return BusinessCalendar::query()->with(['windows', 'holidays'])->firstOrFail();
    }

    /**
     * @param  list<array{weekday: int, opens_at: string, closes_at: string}>  $windows
     * @param  list<array{holiday_date: string, name: string}>  $holidays
     */
    public function update(User $actor, string $name, string $timezone, array $windows, array $holidays): BusinessCalendar
    {
        $this->authorizeManagement($actor);
        $this->validateSchedule($windows);
        $this->validateTimezone($timezone);

        return DB::transaction(function () use ($actor, $name, $timezone, $windows, $holidays): BusinessCalendar {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeManagement($lockedActor);
            $calendar = BusinessCalendar::query()->with('windows', 'holidays')->lockForUpdate()->firstOrFail();
            $before = [
                'name' => $calendar->name,
                'timezone' => $calendar->timezone,
                'windows' => $calendar->windows->map(fn ($window): array => [
                    'weekday' => (int) $window->weekday,
                    'opens_at' => (string) $window->opens_at,
                    'closes_at' => (string) $window->closes_at,
                ])->all(),
                'holidays' => $calendar->holidays->map(fn ($holiday): array => [
                    'holiday_date' => $holiday->holiday_date?->format('Y-m-d'),
                    'name' => $holiday->name,
                ])->all(),
            ];

            $calendar->forceFill(['name' => $name, 'timezone' => $timezone, 'updated_by' => $lockedActor->getKey()])->save();
            $calendar->windows()->delete();
            $calendar->holidays()->delete();
            foreach ($windows as $window) {
                $calendar->windows()->forceCreate($window);
            }
            foreach ($holidays as $holiday) {
                $calendar->holidays()->forceCreate($holiday);
            }

            $this->recordActivity($calendar, $lockedActor, [
                'before' => $before,
                'after' => ['name' => $name, 'timezone' => $timezone, 'windows' => $windows, 'holidays' => $holidays],
            ]);

            return $calendar->load(['windows', 'holidays']);
        }, attempts: 3);
    }

    /** @param list<array{weekday: int, opens_at: string, closes_at: string}> $windows */
    private function validateSchedule(array $windows): void
    {
        if ($windows === []) {
            throw ValidationException::withMessages(['windows' => 'Select at least one working day.']);
        }

        $weekdays = [];
        foreach ($windows as $window) {
            if (in_array($window['weekday'], $weekdays, true)) {
                throw ValidationException::withMessages(['windows' => 'Each weekday can only appear once.']);
            }
            $weekdays[] = $window['weekday'];
            if ($window['opens_at'] >= $window['closes_at']) {
                throw ValidationException::withMessages(['windows' => 'Closing time must be later than opening time.']);
            }
        }
    }

    private function validateTimezone(string $timezone): void
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['timezone' => 'Choose a valid timezone.']);
        }
    }

    private function authorizeManagement(User $actor): void
    {
        if (! in_array('admin', $actor->tyroRoleSlugs(), true) || ! $this->permissions->allows($actor, 'calendars.manage')) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string, mixed> $changes */
    private function recordActivity(BusinessCalendar $calendar, User $actor, array $changes): void
    {
        ActivityEntry::query()->forceCreate([
            'ticket_id' => null,
            'actor_id' => $actor->getKey(),
            'actor_kind' => ActorKind::User,
            'action' => 'business_calendar.updated',
            'visibility' => MessageVisibility::Internal,
            'subject_type' => BusinessCalendar::class,
            'subject_id' => $calendar->getKey(),
            'operation_key' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => CarbonImmutable::now('UTC'),
            'changes' => $changes,
            'metadata' => null,
        ]);
    }
}
