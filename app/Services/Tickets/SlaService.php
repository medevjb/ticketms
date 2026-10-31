<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Models\BusinessCalendar;
use App\Models\Ticket;
use App\Models\TicketSlaInterval;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class SlaService
{
    /**
     * @return array{warning_at: CarbonImmutable, due_at: CarbonImmutable}
     */
    public function creationProjection(
        BusinessCalendar $calendar,
        CarbonImmutable $startedAt,
        int $targetSeconds,
        int $warningPercent,
    ): array {
        $warningSeconds = intdiv(($targetSeconds * $warningPercent) + 99, 100);

        return [
            'warning_at' => $this->addBusinessSeconds($calendar, $startedAt, $warningSeconds),
            'due_at' => $this->addBusinessSeconds($calendar, $startedAt, $targetSeconds),
        ];
    }

    public function consumedSeconds(Ticket $ticket, CarbonImmutable $asOf): int
    {
        $calendar = $ticket->businessCalendar;
        $startedAt = $ticket->sla_started_at;
        if ($calendar === null) {
            throw ValidationException::withMessages(['ticket' => 'The ticket is missing its pinned SLA calendar.']);
        }

        $stopAt = match ($ticket->status) {
            TicketStatus::Resolved, TicketStatus::Closed => $ticket->resolved_at ?? $asOf,
            default => $asOf,
        };
        $elapsed = $this->businessSecondsBetween($calendar, $startedAt, $stopAt);

        foreach ($this->intervals($ticket) as $interval) {
            $intervalStart = $interval->started_at;
            $intervalEnd = $interval->ended_at ?? $stopAt;
            $clippedStart = $intervalStart->greaterThan($startedAt) ? $intervalStart : $startedAt;
            $clippedEnd = $intervalEnd->lessThan($stopAt) ? $intervalEnd : $stopAt;

            if ($clippedEnd->greaterThan($clippedStart)) {
                $elapsed -= $this->businessSecondsBetween($calendar, $clippedStart, $clippedEnd);
            }
        }

        return max(0, $elapsed);
    }

    /**
     * @return array{
     *   state: string,
     *   consumed_seconds: int,
     *   remaining_seconds: int,
     *   warning_at: CarbonImmutable|null,
     *   due_at: CarbonImmutable|null
     * }
     */
    public function snapshot(Ticket $ticket, ?CarbonImmutable $asOf = null): array
    {
        $asOf = ($asOf ?? CarbonImmutable::now('UTC'))->startOfSecond();
        if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            return [
                'state' => 'stopped',
                'consumed_seconds' => (int) $ticket->sla_consumed_seconds,
                'remaining_seconds' => max(0, (int) $ticket->sla_target_seconds - (int) $ticket->sla_consumed_seconds),
                'warning_at' => null,
                'due_at' => null,
            ];
        }

        $consumed = $this->consumedSeconds($ticket, $asOf);
        $remaining = max(0, (int) $ticket->sla_target_seconds - $consumed);
        if ($ticket->status === TicketStatus::WaitingForCustomer) {
            return [
                'state' => 'paused',
                'consumed_seconds' => $consumed,
                'remaining_seconds' => $remaining,
                'warning_at' => null,
                'due_at' => null,
            ];
        }

        $calendar = $ticket->businessCalendar;
        $policy = $ticket->slaPolicy;
        $startedAt = $ticket->sla_started_at;
        if ($calendar === null || $policy === null) {
            throw ValidationException::withMessages(['ticket' => 'The ticket is missing its pinned SLA configuration.']);
        }

        $warningSeconds = intdiv(((int) $ticket->sla_target_seconds * (int) $policy->warning_percent) + 99, 100);
        $intervals = $this->intervals($ticket);
        $atInitialSlaStart = $intervals->isEmpty() && $asOf->equalTo($startedAt->utc()->startOfSecond());
        $warningAt = $atInitialSlaStart
            ? $ticket->sla_warning_at
            : $this->thresholdAt($calendar, $startedAt, $warningSeconds, $intervals, $asOf);
        $dueAt = $atInitialSlaStart
            ? $ticket->sla_due_at
            : $this->thresholdAt($calendar, $startedAt, (int) $ticket->sla_target_seconds, $intervals, $asOf);
        $state = $consumed >= (int) $ticket->sla_target_seconds
            ? 'breached'
            : ($consumed >= $warningSeconds ? 'approaching' : 'within');

        return [
            'state' => $state,
            'consumed_seconds' => $consumed,
            'remaining_seconds' => $remaining,
            'warning_at' => $warningAt,
            'due_at' => $dueAt,
        ];
    }

    /** @return array<string, mixed> */
    public function countdown(Ticket $ticket): array
    {
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $snapshot = $this->snapshot($ticket, $now);
        $countingUntil = null;
        if (! in_array($snapshot['state'], ['paused', 'stopped', 'breached'], true) && $ticket->businessCalendar !== null) {
            $calendar = $ticket->businessCalendar;
            $timezone = $this->timezone($calendar);
            $local = $now->setTimezone($timezone);
            $date = $local->format('Y-m-d');
            $schedule = $this->schedule($calendar, $date, $date);
            $window = $schedule['windows'][$local->isoWeekday()] ?? null;
            if ($window !== null && ! isset($schedule['holidays'][$date])) {
                $opens = CarbonImmutable::parse($date.' '.$window['opens_at'], $timezone)->utc();
                $closes = CarbonImmutable::parse($date.' '.$window['closes_at'], $timezone)->utc();
                if ($now->greaterThanOrEqualTo($opens) && $now->lessThan($closes)) {
                    $countingUntil = $closes->timestamp;
                }
            }
        }

        return [...$snapshot, 'server_time' => $now->timestamp, 'counting_until' => $countingUntil];
    }

    /**
     * @return array{warning_at: CarbonImmutable, due_at: CarbonImmutable}
     */
    public function activeProjection(Ticket $ticket, CarbonImmutable $asOf): array
    {
        $calendar = $ticket->businessCalendar;
        $policy = $ticket->slaPolicy;
        $startedAt = $ticket->sla_started_at;
        if ($calendar === null || $policy === null) {
            throw ValidationException::withMessages(['ticket' => 'The ticket is missing its pinned SLA configuration.']);
        }

        $warningSeconds = intdiv(((int) $ticket->sla_target_seconds * (int) $policy->warning_percent) + 99, 100);
        $intervals = $this->intervals($ticket);

        return [
            'warning_at' => $this->thresholdAt($calendar, $startedAt, $warningSeconds, $intervals, $asOf)
                ?? $this->addBusinessSeconds($calendar, $asOf, max(0, $warningSeconds - $this->consumedSeconds($ticket, $asOf))),
            'due_at' => $this->thresholdAt($calendar, $startedAt, (int) $ticket->sla_target_seconds, $intervals, $asOf)
                ?? $this->addBusinessSeconds($calendar, $asOf, max(0, (int) $ticket->sla_target_seconds - $this->consumedSeconds($ticket, $asOf))),
        ];
    }

    public function breachAt(Ticket $ticket, CarbonImmutable $asOf): ?CarbonImmutable
    {
        $calendar = $ticket->businessCalendar;
        $startedAt = $ticket->sla_started_at;
        if ($calendar === null) {
            throw ValidationException::withMessages(['ticket' => 'The ticket is missing its pinned SLA calendar.']);
        }

        $threshold = (int) $ticket->sla_target_seconds;
        if ($this->consumedSeconds($ticket, $asOf) < $threshold) {
            return null;
        }

        return $this->thresholdAt($calendar, $startedAt, $threshold, $this->intervals($ticket), $asOf);
    }

    public function addBusinessSeconds(BusinessCalendar $calendar, CarbonImmutable $from, int $seconds): CarbonImmutable
    {
        if ($seconds < 0) {
            throw ValidationException::withMessages(['sla' => 'SLA duration cannot be negative.']);
        }

        $timezone = $this->timezone($calendar);
        $cursor = $from->utc()->startOfSecond();
        if ($seconds === 0) {
            return $cursor;
        }

        $localStart = $cursor->setTimezone($timezone);
        $lastDate = $localStart->addYears(10)->toDateString();
        $schedule = $this->schedule($calendar, $localStart->toDateString(), $lastDate);
        $remaining = $seconds;
        $day = $localStart->startOfDay();

        for ($dayCount = 0; $dayCount <= 3660; $dayCount++, $day = $day->addDay()) {
            $date = $day->toDateString();
            $weekday = $day->isoWeekday();
            $window = $schedule['windows'][$weekday] ?? null;
            if ($window === null || isset($schedule['holidays'][$date])) {
                continue;
            }

            $opensAt = CarbonImmutable::parse("{$date} {$window['opens_at']}", $timezone)->utc();
            $closesAt = CarbonImmutable::parse("{$date} {$window['closes_at']}", $timezone)->utc();
            $segmentStart = $cursor->greaterThan($opensAt) ? $cursor : $opensAt;
            if (! $segmentStart->lessThan($closesAt)) {
                continue;
            }

            $available = $closesAt->getTimestamp() - $segmentStart->getTimestamp();
            if ($remaining <= $available) {
                return $segmentStart->addSeconds($remaining);
            }

            $remaining -= $available;
            $cursor = $closesAt;
        }

        throw ValidationException::withMessages(['sla' => 'The configured calendar has no reachable business time within the ten-year calculation limit.']);
    }

    public function businessSecondsBetween(BusinessCalendar $calendar, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $start = $from->utc()->startOfSecond();
        $end = $to->utc()->startOfSecond();
        if (! $end->greaterThan($start)) {
            return 0;
        }

        $timezone = $this->timezone($calendar);
        $localStart = $start->setTimezone($timezone);
        $localEnd = $end->setTimezone($timezone);
        $schedule = $this->schedule($calendar, $localStart->toDateString(), $localEnd->toDateString());
        $seconds = 0;
        $day = $localStart->startOfDay();
        $lastDay = $localEnd->startOfDay();

        while (! $day->greaterThan($lastDay)) {
            $date = $day->toDateString();
            $window = $schedule['windows'][$day->isoWeekday()] ?? null;
            if ($window !== null && ! isset($schedule['holidays'][$date])) {
                $opensAt = CarbonImmutable::parse("{$date} {$window['opens_at']}", $timezone)->utc();
                $closesAt = CarbonImmutable::parse("{$date} {$window['closes_at']}", $timezone)->utc();
                $segmentStart = $start->greaterThan($opensAt) ? $start : $opensAt;
                $segmentEnd = $end->lessThan($closesAt) ? $end : $closesAt;

                if ($segmentEnd->greaterThan($segmentStart)) {
                    $seconds += $segmentEnd->getTimestamp() - $segmentStart->getTimestamp();
                }
            }
            $day = $day->addDay();
        }

        return $seconds;
    }

    /**
     * @param  Collection<int, TicketSlaInterval>  $intervals
     */
    private function thresholdAt(
        BusinessCalendar $calendar,
        CarbonImmutable $startedAt,
        int $thresholdSeconds,
        Collection $intervals,
        CarbonImmutable $asOf,
    ): ?CarbonImmutable {
        $remaining = $thresholdSeconds;
        $cursor = $startedAt->utc()->startOfSecond();
        $asOf = $asOf->utc()->startOfSecond();

        foreach ($intervals as $interval) {
            $intervalStart = $interval->started_at->utc()->startOfSecond();
            if (! $intervalStart->lessThan($asOf)) {
                break;
            }

            $activeEnd = $intervalStart;
            if ($activeEnd->greaterThan($cursor)) {
                $available = $this->businessSecondsBetween($calendar, $cursor, $activeEnd);
                if ($remaining <= $available) {
                    return $this->addBusinessSeconds($calendar, $cursor, $remaining);
                }
                $remaining -= $available;
            }

            if ($interval->ended_at === null) {
                return null;
            }

            $intervalEnd = $interval->ended_at->utc()->startOfSecond();
            if ($intervalEnd->greaterThan($cursor)) {
                $cursor = $intervalEnd;
            }
        }

        if (! $asOf->greaterThan($cursor)) {
            return $remaining === 0 ? $cursor : null;
        }

        $available = $this->businessSecondsBetween($calendar, $cursor, $asOf);
        if ($remaining <= $available) {
            return $this->addBusinessSeconds($calendar, $cursor, $remaining);
        }

        $remaining -= $available;

        return $this->addBusinessSeconds($calendar, $asOf, $remaining);
    }

    /** @return Collection<int, TicketSlaInterval> */
    private function intervals(Ticket $ticket): Collection
    {
        return $ticket->slaIntervals()->orderBy('started_at')->orderBy('id')->get();
    }

    /**
     * @return array{
     *   windows: array<int, array{opens_at: string, closes_at: string}>,
     *   holidays: array<string, true>
     * }
     */
    private function schedule(BusinessCalendar $calendar, string $fromDate, string $toDate): array
    {
        $windows = [];
        foreach ($calendar->windows()->orderBy('weekday')->get() as $window) {
            $windows[(int) $window->weekday] = [
                'opens_at' => (string) $window->opens_at,
                'closes_at' => (string) $window->closes_at,
            ];
        }

        $holidays = [];
        foreach ($calendar->holidays()->whereBetween('holiday_date', [$fromDate, $toDate.' 23:59:59'])->pluck('holiday_date') as $holiday) {
            $holidays[substr((string) $holiday, 0, 10)] = true;
        }

        return ['windows' => $windows, 'holidays' => $holidays];
    }

    private function timezone(BusinessCalendar $calendar): DateTimeZone
    {
        try {
            return new DateTimeZone($calendar->timezone);
        } catch (\Exception) {
            throw ValidationException::withMessages(['calendar' => 'The ticket calendar has an invalid timezone configuration.']);
        }
    }
}
