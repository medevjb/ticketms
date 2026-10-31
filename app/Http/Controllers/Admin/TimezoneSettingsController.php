<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DisplayTimezoneService;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TimezoneSettingsController extends Controller
{
    public function update(Request $request, DisplayTimezoneService $settings): RedirectResponse
    {
        $data = $request->validate(['timezone' => ['required', 'timezone']]);
        try {
            $settings->save($request->user(), $data['timezone']);
        } catch (RuntimeException $exception) {
            report($exception);

            return back()->with('error', 'The timezone could not be saved. Check that environment settings are writable.');
        }

        return to_route(DashboardRoute::name('settings.system.index'))->with('success', 'Display timezone updated. Business calendars and stored timestamps are unchanged.');
    }
}
