<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\SlaPolicies\UpdateSlaPolicyRequest;
use App\Services\SlaPolicies\SlaPolicyService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SlaPolicyController extends BaseController
{
    public function index(SlaPolicyService $policies): View
    {
        $actor = request()->user();

        return view('admin.sla-policies.index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'policy' => $policies->current($actor),
            'priorities' => $policies->priorities($actor),
        ]));
    }

    public function update(UpdateSlaPolicyRequest $request, SlaPolicyService $policies): RedirectResponse
    {
        $data = $request->validated();
        $policies->update(
            $request->user(),
            (string) $data['name'],
            (int) $data['warning_percent'],
            $request->targetData(),
        );

        return to_route('dashboard.sla-policies.index')->with('success', 'SLA policy updated. Changes are active immediately for new tickets.');
    }
}
