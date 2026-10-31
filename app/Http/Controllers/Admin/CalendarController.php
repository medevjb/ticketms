<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Calendars\UpdateBusinessCalendarRequest;
use App\Services\Calendars\CalendarService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CalendarController extends BaseController
{
    public function index(CalendarService $calendars): View
    {
        return view('admin.calendars.index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'calendar' => $calendars->current(request()->user()),
        ]));
    }

    public function update(UpdateBusinessCalendarRequest $request, CalendarService $calendars): RedirectResponse
    {
        $data = $request->validated();
        $calendars->update(
            $request->user(),
            (string) $data['name'],
            (string) $data['timezone'],
            $request->windowData(),
            $request->holidayData(),
        );

        return to_route('dashboard.calendars.index')->with('success', 'Business calendar updated. Changes are active immediately.');
    }
}
