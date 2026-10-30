<?php

namespace App\Http\Controllers\Admin;

use App\Services\Tickets\NotificationCenterService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends BaseController
{
    public function index(Request $request, NotificationCenterService $notifications): View
    {
        return view('admin.notifications.index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'notifications' => $notifications->paginate($request->user()),
        ]));
    }

    public function markRead(Request $request, string $notification, NotificationCenterService $notifications): RedirectResponse
    {
        $notifications->markRead($request->user(), $notification);

        return to_route('dashboard.notifications.index')->with('success', 'Notification marked as read.');
    }

    public function markAllRead(Request $request, NotificationCenterService $notifications): RedirectResponse
    {
        $notifications->markAllRead($request->user());

        return to_route('dashboard.notifications.index')->with('success', 'Visible notifications marked as read.');
    }
}
