<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Analytics\AnalyticsFilterRequest;
use App\Services\Analytics\TicketAnalyticsService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class AnalyticsController extends BaseController
{
    public function index(AnalyticsFilterRequest $request, TicketAnalyticsService $analytics): View
    {
        $filters = $request->validated();

        $data = $analytics->dashboard($request->user(), $filters['from'] ?? null, $filters['to'] ?? null);
        /** @var list<array{date: string, created: int, resolved: int, closed: int}> $dailyData */
        $dailyData = $data['daily'];
        $activeDays = collect($dailyData)
            ->filter(fn (array $day): bool => $day['created'] + $day['resolved'] + $day['closed'] > 0);
        $order = $filters['activity_order'] ?? 'desc';
        $activeDays = ($order === 'asc' ? $activeDays->sortBy('date') : $activeDays->sortByDesc('date'))->values();
        $perPage = (int) ($filters['activity_per_page'] ?? 10);
        $page = min((int) ($filters['activity_page'] ?? 1), max(1, (int) ceil($activeDays->count() / $perPage)));
        $dailyActivity = new LengthAwarePaginator(
            $activeDays->forPage($page, $perPage)->values(),
            $activeDays->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'pageName' => 'activity_page'],
        );
        $dailyActivity->appends([
            ...$data['filters'], 'activity_per_page' => $perPage, 'activity_order' => $order,
        ])->fragment('daily-activity');

        return view('admin.analytics.index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'analytics' => $data,
            'dailyActivity' => $dailyActivity,
            'activityOrder' => $order,
            'activityPeak' => max(1, $activeDays->max(fn (array $day): int => $day['created'] + $day['resolved'] + $day['closed']) ?? 0),
            'activityTotals' => [
                'created' => $activeDays->sum('created'),
                'resolved' => $activeDays->sum('resolved'),
                'closed' => $activeDays->sum('closed'),
            ],
        ]));
    }
}
