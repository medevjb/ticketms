<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\Tickets\NotificationCenterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationCenterService $notifications): View
    {
        return view('customer.notifications.index', [
            'notifications' => $notifications->paginate($request->user()),
        ]);
    }

    public function markRead(Request $request, string $notification, NotificationCenterService $notifications): RedirectResponse
    {
        $notifications->markRead($request->user(), $notification);

        return to_route('customer.notifications.index')->with('success', 'Notification marked as read.');
    }

    public function markAllRead(Request $request, NotificationCenterService $notifications): RedirectResponse
    {
        $notifications->markAllRead($request->user());

        return to_route('customer.notifications.index')->with('success', 'Notifications marked as read.');
    }
}
