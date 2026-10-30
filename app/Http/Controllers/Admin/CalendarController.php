<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CalendarBulkAction;
use App\Enums\PublicationState;
use App\Http\Requests\Calendars\BulkCalendarRequest;
use App\Http\Requests\Calendars\CalendarAvailabilityRequest;
use App\Http\Requests\Calendars\CalendarIndexRequest;
use App\Http\Requests\Calendars\CloneCalendarRequest;
use App\Http\Requests\Calendars\UpdateCalendarDraftRequest;
use App\Models\CalendarVersion;
use App\Models\TicketingSetting;
use App\Services\Calendars\CalendarService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CalendarController extends BaseController
{
    public function index(CalendarIndexRequest $request, CalendarService $calendars): View
    {
        $items = $calendars->paginate($request->user(), $request->validated());
        $currentPolicy = TicketingSetting::query()->with('currentSlaPolicy')->findOrFail(1)->currentSlaPolicy;

        return view('admin.calendars.index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'calendars' => $items->withQueryString(),
            'filters' => array_merge(['status' => 'all', 'search' => ''], $request->validated()),
            'publishedCalendars' => $calendars->publishedCalendars($request->user()),
            'currentCalendarId' => $currentPolicy?->calendar_version_id,
        ]));
    }

    public function clone(CloneCalendarRequest $request, CalendarService $calendars): RedirectResponse
    {
        $calendar = $calendars->cloneDraft($request->user(), (int) $request->validated('source_calendar_id'));

        return to_route('dashboard.calendars.edit', $calendar)->with('success', "Draft calendar v{$calendar->version_no} created.");
    }

    public function edit(CalendarVersion $calendar): View
    {
        if ($calendar->publication_state !== PublicationState::Draft) {
            abort(404);
        }

        return view('admin.calendars.edit', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'calendar' => $calendar->load(['windows', 'holidays']),
        ]));
    }

    public function update(UpdateCalendarDraftRequest $request, CalendarVersion $calendar, CalendarService $calendars): RedirectResponse
    {
        $updated = $calendars->updateDraft(
            $calendar,
            $request->user(),
            (string) $request->validated('name'),
            $request->windowData(),
            $request->holidayData(),
        );

        return to_route('dashboard.calendars.edit', $updated)->with('success', 'Calendar draft saved.');
    }

    public function publish(CalendarVersion $calendar, CalendarService $calendars): RedirectResponse
    {
        $calendars->publish($calendar, request()->user());

        return to_route('dashboard.calendars.index')->with('success', "Calendar v{$calendar->version_no} published. It becomes current when an SLA policy using it is published.");
    }

    public function availability(CalendarAvailabilityRequest $request, CalendarVersion $calendar, CalendarService $calendars): RedirectResponse
    {
        $calendars->setActive($calendar, $request->boolean('is_active'), $request->user());

        return back()->with('success', $request->boolean('is_active') ? 'Calendar enabled.' : 'Calendar disabled.');
    }

    public function archive(CalendarVersion $calendar, CalendarService $calendars): RedirectResponse
    {
        $calendars->archive($calendar, request()->user());

        return to_route('dashboard.calendars.index', ['status' => 'archived'])->with('success', 'Calendar version archived from selection. Historical references are preserved.');
    }

    public function restore(int $calendar, CalendarService $calendars): RedirectResponse
    {
        $calendars->restore($calendar, request()->user());

        return to_route('dashboard.calendars.index', ['status' => 'inactive'])->with('success', 'Calendar restored as disabled.');
    }

    public function bulk(BulkCalendarRequest $request, CalendarService $calendars): RedirectResponse
    {
        $result = $calendars->bulk(
            $request->user(),
            CalendarBulkAction::from($request->validated('action')),
            array_values(array_map('intval', $request->validated('selected_ids'))),
            (string) $request->validated('operation_key'),
        );
        $redirect = back()->with('success', "Bulk action completed for {$result['succeeded']} calendar versions.");
        if ($result['failed'] !== []) {
            $redirect->with('bulk_failures', $result['failed']);
        }

        return $redirect;
    }
}
