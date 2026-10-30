<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PriorityBulkAction;
use App\Http\Requests\Priorities\BulkPriorityRequest;
use App\Http\Requests\Priorities\PriorityAvailabilityRequest;
use App\Http\Requests\Priorities\PriorityIndexRequest;
use App\Http\Requests\Priorities\StorePriorityRequest;
use App\Http\Requests\Priorities\UpdatePriorityRequest;
use App\Models\Priority;
use App\Services\Priorities\PriorityService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PriorityController extends BaseController
{
    public function index(PriorityIndexRequest $request, PriorityService $priorities): View
    {
        $items = $priorities->paginate($request->user(), $request->validated());

        return view('admin.priorities.index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'priorities' => $items->withQueryString(),
            'filters' => array_merge(['status' => 'active', 'search' => ''], $request->validated()),
        ]));
    }

    public function create(): View
    {
        return view('admin.priorities.create', $this->getViewData(['dashboardRoute' => DashboardRoute::class]));
    }

    public function store(StorePriorityRequest $request, PriorityService $priorities): RedirectResponse
    {
        $data = $request->validated();
        $priorities->create($request->user(), (string) $data['name'], (string) $data['slug'], (int) $data['sort_order']);

        return to_route('dashboard.priorities.index', ['status' => 'inactive'])
            ->with('success', 'Priority created as disabled. Add its SLA target to a draft policy before enabling it.');
    }

    public function edit(Priority $priority): View
    {
        return view('admin.priorities.edit', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'priority' => $priority,
        ]));
    }

    public function update(UpdatePriorityRequest $request, Priority $priority, PriorityService $priorities): RedirectResponse
    {
        $data = $request->validated();
        $priorities->update($priority, $request->user(), (string) $data['name'], (int) $data['sort_order']);

        return to_route('dashboard.priorities.index')->with('success', 'Priority updated successfully.');
    }

    public function availability(PriorityAvailabilityRequest $request, Priority $priority, PriorityService $priorities): RedirectResponse
    {
        $priorities->setActive($priority, $request->boolean('is_active'), $request->user());

        return back()->with('success', $request->boolean('is_active') ? 'Priority enabled.' : 'Priority disabled.');
    }

    public function archive(Priority $priority, PriorityService $priorities): RedirectResponse
    {
        $priorities->archive($priority, request()->user());

        return to_route('dashboard.priorities.index', ['status' => 'archived'])->with('success', 'Priority archived. Historical tickets retain their priority.');
    }

    public function restore(int $priority, PriorityService $priorities): RedirectResponse
    {
        $priorities->restore($priority, request()->user());

        return to_route('dashboard.priorities.index', ['status' => 'inactive'])->with('success', 'Priority restored as disabled.');
    }

    public function bulk(BulkPriorityRequest $request, PriorityService $priorities): RedirectResponse
    {
        $result = $priorities->bulk(
            $request->user(),
            PriorityBulkAction::from($request->validated('action')),
            array_values(array_map('intval', $request->validated('selected_ids'))),
        );
        $redirect = back()->with('success', "Bulk action completed for {$result['succeeded']} priorities.");

        if ($result['failed'] !== []) {
            $redirect->with('bulk_failures', $result['failed']);
        }

        return $redirect;
    }
}
