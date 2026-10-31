<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Agents\SetAgentAvailabilityRequest;
use App\Http\Requests\Agents\StoreAgentRequest;
use App\Mail\AgentInvitationMail;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\Agents\AgentManagementService;
use Carbon\CarbonImmutable;
use HasinHayder\Tyro\Support\TyroAudit;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

class AgentManagementController extends BaseController
{
    public function index(Request $request, AgentManagementService $agents): View
    {
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 150);

        return view('admin.agents.index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'agents' => $agents->list($request->user(), $search),
            'search' => $search,
        ]));
    }

    public function create(): View
    {
        return view('admin.agents.create', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
        ]));
    }

    public function store(StoreAgentRequest $request, AgentManagementService $agents): RedirectResponse
    {
        $data = $request->validated();
        $details = $agents->createInvitedAgent(
            $request->user(),
            $data['name'],
            $data['email'],
            (bool) $data['auto_assignment_enabled'],
        );
        $profile = $details['profile'];
        $this->auditSafely('agent.invited', $profile, null, [
            'user_id' => $details['user']->getKey(),
            'email' => $details['user']->email,
        ]);

        if (! $this->sendInvitation($details)) {
            return to_route('dashboard.agents.index')
                ->with('error', 'The agent account was created, but the invitation email could not be sent. Resend the invitation from the agent list.');
        }

        return to_route('dashboard.agents.index')->with('success', "An invitation was sent to {$details['user']->email}.");
    }

    public function createProfile(int $agent, Request $request, AgentManagementService $agents): RedirectResponse
    {
        $profile = $agents->createMissingProfile($request->user(), $agent);
        $this->auditSafely('agent.profile_created', $profile, null, ['user_id' => $profile->user_id]);

        return to_route('dashboard.agents.index')->with('success', 'Agent profile created. Automatic assignment is off until you enable it.');
    }

    public function resendInvitation(int $agent, Request $request, AgentManagementService $agents): RedirectResponse
    {
        $details = $agents->resendInvitation($request->user(), $agent);
        $this->auditSafely('agent.invitation_resent', $details['profile'], null, ['user_id' => $agent]);

        if (! $this->sendInvitation($details)) {
            return to_route('dashboard.agents.index')
                ->with('error', 'The invitation could not be sent. Try resending it again.');
        }

        return to_route('dashboard.agents.index')->with('success', "A new invitation was sent to {$details['user']->email}. The previous link is no longer valid.");
    }

    public function setActive(SetAgentAvailabilityRequest $request, int $agent, AgentManagementService $agents): RedirectResponse
    {
        $isActive = $request->boolean('is_active');
        $target = User::query()->findOrFail($agent);
        $wasActive = $target->is_active;
        $agents->setActive($request->user(), $agent, $isActive);
        $this->auditSafely('agent.availability_changed', $target, ['is_active' => $wasActive], ['is_active' => $isActive]);

        return to_route('dashboard.agents.index')->with('success', $isActive ? 'Agent account enabled.' : 'Agent account disabled. Active tickets were unassigned.');
    }

    /**
     * @param  array{user: User, profile: AgentProfile, token: string, expires_at: CarbonImmutable}  $details
     */
    private function sendInvitation(array $details): bool
    {
        try {
            Mail::to($details['user']->email)->send(new AgentInvitationMail(
                $details['user']->name,
                route('agent-invitations.show', $details['token']),
                $details['expires_at']->format('M j, Y H:i').' UTC',
            ));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Keep an audit logging failure from interrupting agent management actions.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    protected function auditSafely(string $event, mixed $auditable = null, ?array $oldValues = null, ?array $newValues = null): void
    {
        try {
            TyroAudit::log($event, $auditable, $oldValues, $newValues);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
