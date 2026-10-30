<?php

namespace App\Services\Calendars;

use App\Enums\ActorKind;
use App\Enums\CalendarBulkAction;
use App\Enums\MessageVisibility;
use App\Enums\PublicationState;
use App\Models\ActivityEntry;
use App\Models\CalendarVersion;
use App\Models\SlaPolicy;
use App\Models\TicketingSetting;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use App\Services\BulkOperations\BulkOperationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CalendarService
{
    public function __construct(
        private readonly EffectivePermissionService $permissions,
        private readonly BulkOperationService $bulkOperations,
    ) {}

    /** @param array{search?: string|null, status?: string|null} $filters
     * @return LengthAwarePaginator<int, CalendarVersion>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $this->authorizeManagement($actor);
        $status = in_array($filters['status'] ?? 'all', ['all', 'draft', 'published', 'inactive', 'archived'], true)
            ? ($filters['status'] ?? 'all')
            : 'all';
        $query = CalendarVersion::query()->with(['windows', 'holidays']);

        if (in_array($status, ['all', 'archived'], true)) {
            $query->withTrashed();
        }
        if ($status === 'draft') {
            $query->where('publication_state', PublicationState::Draft);
        } elseif ($status === 'published') {
            $query->where('publication_state', PublicationState::Published)->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status === 'archived') {
            $query->onlyTrashed();
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->orderByDesc('version_no')->paginate(15);
    }

    /** @return list<CalendarVersion> */
    public function publishedCalendars(User $actor): array
    {
        $this->authorizeManagement($actor);

        return array_values(CalendarVersion::query()
            ->where('publication_state', PublicationState::Published)
            ->where('is_active', true)
            ->orderByDesc('version_no')
            ->get()
            ->all());
    }

    public function cloneDraft(User $actor, int $sourceCalendarId): CalendarVersion
    {
        $this->authorizeManagement($actor);

        return DB::transaction(function () use ($actor, $sourceCalendarId): CalendarVersion {
            $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $source = CalendarVersion::query()->whereKey($sourceCalendarId)->lockForUpdate()->firstOrFail();
            if ($source->publication_state !== PublicationState::Published || ! $source->is_active) {
                throw ValidationException::withMessages(['source_calendar_id' => 'Choose an enabled, published calendar version.']);
            }

            $version = (int) CalendarVersion::withTrashed()->max('version_no') + 1;
            $draft = new CalendarVersion;
            $draft->forceFill([
                'version_no' => $version,
                'name' => mb_substr("Draft of {$source->name}", 0, 100),
                'timezone' => 'Asia/Dhaka',
                'publication_state' => PublicationState::Draft,
                'is_active' => true,
                'created_by' => $actor->getKey(),
            ])->save();

            foreach ($source->windows()->orderBy('weekday')->get() as $window) {
                $draft->windows()->forceCreate([
                    'weekday' => $window->weekday,
                    'opens_at' => $window->opens_at,
                    'closes_at' => $window->closes_at,
                ]);
            }
            foreach ($source->holidays()->orderBy('holiday_date')->get() as $holiday) {
                $draft->holidays()->forceCreate(['holiday_date' => $holiday->holiday_date, 'name' => $holiday->name]);
            }

            $this->recordActivity($draft, $actor, 'calendar.draft_created', [
                'source_version' => $source->version_no,
                'window_count' => $draft->windows()->count(),
                'holiday_count' => $draft->holidays()->count(),
            ]);

            return $draft;
        }, attempts: 3);
    }

    /** @param list<array{weekday: int, opens_at: string, closes_at: string}> $windows
     * @param  list<array{holiday_date: string, name: string}>  $holidays
     */
    public function updateDraft(CalendarVersion $calendar, User $actor, string $name, array $windows, array $holidays): CalendarVersion
    {
        $this->authorizeManagement($actor);

        return DB::transaction(function () use ($calendar, $actor, $name, $windows, $holidays): CalendarVersion {
            $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $locked = CalendarVersion::query()->whereKey($calendar->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureDraft($locked);
            $this->validateSchedule($windows);

            $before = [
                'name' => $locked->name,
                'windows' => $locked->windows()->orderBy('weekday')->get(['weekday', 'opens_at', 'closes_at'])->toArray(),
                'holidays' => $locked->holidays()->orderBy('holiday_date')->get(['holiday_date', 'name'])->toArray(),
            ];
            $locked->forceFill(['name' => $name, 'updated_by' => $actor->getKey()])->save();
            $locked->windows()->delete();
            $locked->holidays()->delete();

            foreach ($windows as $window) {
                $locked->windows()->forceCreate($window);
            }
            foreach ($holidays as $holiday) {
                $locked->holidays()->forceCreate($holiday);
            }

            $this->recordActivity($locked, $actor, 'calendar.draft_updated', [
                'before' => $before,
                'after' => ['name' => $name, 'windows' => $windows, 'holidays' => $holidays],
            ]);

            return $locked->load(['windows', 'holidays']);
        }, attempts: 3);
    }

    public function publish(CalendarVersion $calendar, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($calendar, $actor): void {
            $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $locked = CalendarVersion::query()->whereKey($calendar->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureDraft($locked);
            $windows = $locked->windows()->orderBy('weekday')->get();
            if ($windows->isEmpty()) {
                throw ValidationException::withMessages(['windows' => 'Add at least one business-hours window before publishing.']);
            }
            $this->validateSchedule(array_values($windows->map(fn ($window): array => [
                'weekday' => (int) $window->weekday,
                'opens_at' => (string) $window->opens_at,
                'closes_at' => (string) $window->closes_at,
            ])->all()));
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['calendar' => 'Enable the calendar before publishing it.']);
            }

            $locked->forceFill([
                'publication_state' => PublicationState::Published,
                'published_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();
            $this->recordActivity($locked, $actor, 'calendar.published', [
                'version_no' => $locked->version_no,
                'window_count' => $windows->count(),
                'holiday_count' => $locked->holidays()->count(),
            ]);
        }, attempts: 3);
    }

    public function setActive(CalendarVersion $calendar, bool $isActive, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($calendar, $isActive, $actor): void {
            $settings = $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $locked = CalendarVersion::query()->whereKey($calendar->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureNotCurrent($locked, $settings);
            if ($locked->is_active === $isActive) {
                return;
            }

            $locked->forceFill(['is_active' => $isActive, 'updated_by' => $actor->getKey()])->save();
            $this->recordActivity($locked, $actor, $isActive ? 'calendar.enabled' : 'calendar.disabled', ['is_active' => $isActive]);
        }, attempts: 3);
    }

    public function archive(CalendarVersion $calendar, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($calendar, $actor): void {
            $settings = $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $locked = CalendarVersion::query()->whereKey($calendar->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureNotCurrent($locked, $settings);
            $locked->forceFill(['is_active' => false, 'updated_by' => $actor->getKey(), 'deleted_by' => $actor->getKey()])->save();
            $locked->delete();
            $this->recordActivity($locked, $actor, 'calendar.archived', ['is_active' => false]);
        }, attempts: 3);
    }

    public function restore(int $calendarId, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($calendarId, $actor): void {
            $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $calendar = CalendarVersion::withTrashed()->whereKey($calendarId)->lockForUpdate()->firstOrFail();
            if (! $calendar->trashed()) {
                return;
            }

            $calendar->restore();
            $calendar->forceFill(['is_active' => false, 'updated_by' => $actor->getKey(), 'deleted_by' => null])->save();
            $this->recordActivity($calendar, $actor, 'calendar.restored', ['is_active' => false]);
        }, attempts: 3);
    }

    /** @param list<int> $calendarIds
     * @return array{succeeded: int, failed: list<array{id: int, message: string}>}
     */
    public function bulk(User $actor, CalendarBulkAction $action, array $calendarIds): array
    {
        $this->authorizeManagement($actor);

        return $this->bulkOperations->execute(
            $actor,
            'calendars.manage',
            $calendarIds,
            function (int $calendarId) use ($action, $actor): void {
                $calendar = CalendarVersion::withTrashed()->findOrFail($calendarId);
                match ($action) {
                    CalendarBulkAction::Enable => $this->setActive($calendar, true, $actor),
                    CalendarBulkAction::Disable => $this->setActive($calendar, false, $actor),
                    CalendarBulkAction::Archive => $this->archive($calendar, $actor),
                    CalendarBulkAction::Restore => $this->restore($calendarId, $actor),
                };
            },
        );
    }

    private function lockSettings(): TicketingSetting
    {
        return TicketingSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
    }

    private function lockAndAuthorizeActor(User $actor): User
    {
        $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
        $this->authorizeManagement($lockedActor);

        return $lockedActor;
    }

    private function ensureDraft(CalendarVersion $calendar): void
    {
        if ($calendar->publication_state !== PublicationState::Draft) {
            throw ValidationException::withMessages(['calendar' => 'Published calendar versions are immutable. Clone this version to make changes.']);
        }
    }

    /** @param list<array{weekday: int, opens_at: string, closes_at: string}> $windows */
    private function validateSchedule(array $windows): void
    {
        $weekdays = [];
        foreach ($windows as $window) {
            if (in_array($window['weekday'], $weekdays, true)) {
                throw ValidationException::withMessages(['windows' => 'A weekday may have only one business-hours window.']);
            }
            $weekdays[] = $window['weekday'];
            if ($window['opens_at'] >= $window['closes_at']) {
                throw ValidationException::withMessages(['windows' => 'Each closing time must be later than its opening time.']);
            }
        }
    }

    private function ensureNotCurrent(CalendarVersion $calendar, TicketingSetting $settings): void
    {
        $currentCalendarId = SlaPolicy::query()->whereKey($settings->current_sla_policy_id)->value('calendar_version_id');
        if ($currentCalendarId !== null && (int) $currentCalendarId === (int) $calendar->getKey()) {
            throw ValidationException::withMessages(['calendar' => 'The calendar used by the current SLA policy cannot be disabled or archived until a replacement policy is published.']);
        }
    }

    private function authorizeManagement(User $actor): void
    {
        if (! in_array('admin', $actor->tyroRoleSlugs(), true) || ! $this->permissions->allows($actor, 'calendars.manage')) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string, mixed> $changes */
    private function recordActivity(CalendarVersion $calendar, User $actor, string $action, array $changes): void
    {
        ActivityEntry::query()->forceCreate([
            'ticket_id' => null,
            'actor_id' => $actor->getKey(),
            'actor_kind' => ActorKind::User,
            'action' => $action,
            'visibility' => MessageVisibility::Internal,
            'subject_type' => CalendarVersion::class,
            'subject_id' => $calendar->getKey(),
            'operation_key' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
            'changes' => $changes,
            'metadata' => null,
        ]);
    }
}
