<?php

namespace App\Services\SlaPolicies;

use App\Enums\ActorKind;
use App\Enums\MessageVisibility;
use App\Models\ActivityEntry;
use App\Models\BusinessCalendar;
use App\Models\Priority;
use App\Models\SlaPolicy;
use App\Models\SlaPolicyTarget;
use App\Models\TicketingSetting;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SlaPolicyService
{
    public function __construct(private readonly EffectivePermissionService $permissions) {}

    public function current(User $actor): SlaPolicy
    {
        $this->authorizeManagement($actor);

        return SlaPolicy::query()->with('targets.priority')->firstOrFail();
    }

    /** @return list<Priority> */
    public function priorities(User $actor): array
    {
        $this->authorizeManagement($actor);

        return array_values(Priority::query()->orderBy('sort_order')->orderBy('id')->get()->all());
    }

    /** @param list<array{priority_id: int, target_seconds: int}> $targets */
    public function update(User $actor, string $name, int $warningPercent, array $targets): SlaPolicy
    {
        $this->authorizeManagement($actor);
        $this->validateTargets($targets);

        return DB::transaction(function () use ($actor, $name, $warningPercent, $targets): SlaPolicy {
            TicketingSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeManagement($lockedActor);
            $policy = SlaPolicy::query()->with('targets.priority')->lockForUpdate()->firstOrFail();
            $before = [
                'name' => $policy->name,
                'warning_percent' => $policy->warning_percent,
                'targets' => $policy->targets->map(fn (SlaPolicyTarget $target): array => [
                    'priority_id' => (int) $target->priority_id,
                    'target_seconds' => (int) $target->target_seconds,
                ])->values()->all(),
            ];

            $policy->forceFill(['name' => $name, 'warning_percent' => $warningPercent, 'updated_by' => $lockedActor->getKey()])->save();
            $targetPriorityIds = [];
            foreach ($targets as $target) {
                $policy->targets()->updateOrCreate(
                    ['priority_id' => $target['priority_id']],
                    ['target_seconds' => $target['target_seconds']],
                );
                $targetPriorityIds[] = $target['priority_id'];
            }
            $policy->targets()->whereNotIn('priority_id', $targetPriorityIds)->delete();

            $this->recordActivity($policy, $lockedActor, [
                'before' => $before,
                'after' => ['name' => $name, 'warning_percent' => $warningPercent, 'targets' => $targets],
            ]);

            return $policy->load('targets.priority');
        }, attempts: 3);
    }

    /** @param list<array{priority_id: int, target_seconds: int}> $targets */
    private function validateTargets(array $targets): void
    {
        if ($targets === []) {
            throw ValidationException::withMessages(['targets' => 'Add at least one priority target.']);
        }

        $calendar = BusinessCalendar::query()->with('windows')->firstOrFail();
        if ($calendar->windows->isEmpty()) {
            throw ValidationException::withMessages(['targets' => 'Add at least one working day to the business calendar first.']);
        }

        $weeklySeconds = 0;
        foreach ($calendar->windows as $window) {
            $weeklySeconds += $this->timeSeconds((string) $window->closes_at) - $this->timeSeconds((string) $window->opens_at);
        }
        $maxTargetSeconds = intdiv($weeklySeconds, $calendar->windows->count()) * 365;
        $priorityIds = [];
        foreach ($targets as $target) {
            if (in_array($target['priority_id'], $priorityIds, true)) {
                throw ValidationException::withMessages(['targets' => 'Each priority can only have one target.']);
            }
            if ($target['target_seconds'] < 60 || $target['target_seconds'] > $maxTargetSeconds) {
                throw ValidationException::withMessages(['targets' => 'Each target must be at least one minute and no more than 365 configured business days.']);
            }
            if (! Priority::query()->whereKey($target['priority_id'])->exists()) {
                throw ValidationException::withMessages(['targets' => 'A selected priority could not be found.']);
            }
            $priorityIds[] = $target['priority_id'];
        }

        $missingActivePriorityTarget = Priority::query()
            ->active()
            ->whereNotIn('id', $priorityIds)
            ->exists();

        if ($missingActivePriorityTarget) {
            throw ValidationException::withMessages(['targets' => 'Every active priority must have a resolution target.']);
        }
    }

    private function timeSeconds(string $time): int
    {
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return ($hour * 3600) + ($minute * 60) + $second;
    }

    private function authorizeManagement(User $actor): void
    {
        if (! in_array('admin', $actor->tyroRoleSlugs(), true) || ! $this->permissions->allows($actor, 'sla.manage')) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string, mixed> $changes */
    private function recordActivity(SlaPolicy $policy, User $actor, array $changes): void
    {
        ActivityEntry::query()->forceCreate([
            'ticket_id' => null,
            'actor_id' => $actor->getKey(),
            'actor_kind' => ActorKind::User,
            'action' => 'sla_policy.updated',
            'visibility' => MessageVisibility::Internal,
            'subject_type' => SlaPolicy::class,
            'subject_id' => $policy->getKey(),
            'operation_key' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
            'changes' => $changes,
            'metadata' => null,
        ]);
    }
}
