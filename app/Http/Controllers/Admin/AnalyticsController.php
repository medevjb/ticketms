<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Analytics\AnalyticsFilterRequest;
use App\Services\Analytics\TicketAnalyticsService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\View\View;

class AnalyticsController extends BaseController
{
    public function index(AnalyticsFilterRequest $request, TicketAnalyticsService $analytics): View
    {
        $filters = $request->validated();

        return view('admin.analytics.index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'analytics' => $analytics->dashboard(
                $request->user(),
                $filters['from'] ?? null,
                $filters['to'] ?? null,
            ),
        ]));
    }
}
