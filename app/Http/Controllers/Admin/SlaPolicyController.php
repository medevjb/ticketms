<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PublicationState;
use App\Enums\SlaPolicyBulkAction;
use App\Http\Requests\SlaPolicies\BulkSlaPolicyRequest;
use App\Http\Requests\SlaPolicies\CloneSlaPolicyRequest;
use App\Http\Requests\SlaPolicies\SlaPolicyAvailabilityRequest;
use App\Http\Requests\SlaPolicies\SlaPolicyIndexRequest;
use App\Http\Requests\SlaPolicies\UpdateSlaPolicyDraftRequest;
use App\Models\Priority;
use App\Models\SlaPolicy;
use App\Models\TicketingSetting;
use App\Services\SlaPolicies\SlaPolicyService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SlaPolicyController extends BaseController
{
    public function index(SlaPolicyIndexRequest $request, SlaPolicyService $policies): View
    {
        $items = $policies->paginate($request->user(), $request->validated());
        $currentPolicyId = TicketingSetting::query()->value('current_sla_policy_id');

        return view('admin.sla-policies.index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'policies' => $items->withQueryString(),
            'filters' => array_merge(['status' => 'all', 'search' => ''], $request->validated()),
            'publishedPolicies' => $policies->publishedPolicies($request->user()),
            'currentPolicyId' => $currentPolicyId,
        ]));
    }

    public function clone(CloneSlaPolicyRequest $request, SlaPolicyService $policies): RedirectResponse
    {
        $policy = $policies->cloneDraft($request->user(), (int) $request->validated('source_policy_id'));

        return to_route('dashboard.sla-policies.edit', $policy)->with('success', "Draft SLA policy v{$policy->version_no} created.");
    }

    public function edit(SlaPolicy $policy, SlaPolicyService $policies): View
    {
        if ($policy->publication_state !== PublicationState::Draft) {
            abort(404);
        }

        $policy->load(['calendarVersion', 'targets.priority']);
        $priorities = Priority::query()->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
        foreach ($policy->targets as $target) {
            if ($target->priority !== null && ! $priorities->has($target->priority_id)) {
                $priorities->put($target->priority_id, $target->priority);
            }
        }
        $calendars = collect($policies->usableCalendars(request()->user()));
        if ($policy->calendarVersion !== null && ! $calendars->contains('id', $policy->calendar_version_id)) {
            $calendars->push($policy->calendarVersion);
        }

        return view('admin.sla-policies.edit', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'policy' => $policy,
            'priorities' => $priorities->values(),
            'calendars' => $calendars,
        ]));
    }

    public function update(UpdateSlaPolicyDraftRequest $request, SlaPolicy $policy, SlaPolicyService $policies): RedirectResponse
    {
        $updated = $policies->updateDraft(
            $policy,
            $request->user(),
            (string) $request->validated('name'),
            (int) $request->validated('calendar_version_id'),
            (int) $request->validated('warning_percent'),
            $request->targetData(),
        );

        return to_route('dashboard.sla-policies.edit', $updated)->with('success', 'SLA policy draft saved.');
    }

    public function publish(SlaPolicy $policy, SlaPolicyService $policies): RedirectResponse
    {
        $policies->publish($policy, request()->user());

        return to_route('dashboard.sla-policies.index')->with('success', "SLA policy v{$policy->version_no} published and selected for new tickets. Existing tickets retain their policy.");
    }

    public function availability(SlaPolicyAvailabilityRequest $request, SlaPolicy $policy, SlaPolicyService $policies): RedirectResponse
    {
        $policies->setActive($policy, $request->boolean('is_active'), $request->user());

        return back()->with('success', $request->boolean('is_active') ? 'SLA policy enabled.' : 'SLA policy disabled.');
    }

    public function archive(SlaPolicy $policy, SlaPolicyService $policies): RedirectResponse
    {
        $policies->archive($policy, request()->user());

        return to_route('dashboard.sla-policies.index', ['status' => 'archived'])->with('success', 'SLA policy archived from future selection. Historical references are preserved.');
    }

    public function restore(int $policy, SlaPolicyService $policies): RedirectResponse
    {
        $policies->restore($policy, request()->user());

        return to_route('dashboard.sla-policies.index', ['status' => 'inactive'])->with('success', 'SLA policy restored as disabled.');
    }

    public function bulk(BulkSlaPolicyRequest $request, SlaPolicyService $policies): RedirectResponse
    {
        $result = $policies->bulk(
            $request->user(),
            SlaPolicyBulkAction::from($request->validated('action')),
            array_values(array_map('intval', $request->validated('selected_ids'))),
        );
        $redirect = back()->with('success', "Bulk action completed for {$result['succeeded']} SLA policies.");
        if ($result['failed'] !== []) {
            $redirect->with('bulk_failures', $result['failed']);
        }

        return $redirect;
    }
}
