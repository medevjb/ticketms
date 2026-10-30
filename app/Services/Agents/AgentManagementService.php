<?php

namespace App\Services\Agents;

use App\Models\AgentProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use App\Services\Authorization\UserLifecycleService;
use App\Services\Tickets\AssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentManagementService
{
    public function __construct(
        private readonly EffectivePermissionService $permissions,
        private readonly AssignmentService $assignments,
        private readonly UserLifecycleService $userLifecycle,
    ) {}

    public function list(User $actor, string $search = ''): LengthAwarePaginator
    {
        $this->authorizeManagement($actor);

        $query = User::query()
            ->withTrashed()
            ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery
                ->where('name', 'agent')
                ->where('guard_name', 'web'))
            ->with([
                'agentProfile' => fn ($profileQuery) => $profileQuery->withTrashed(),
            ])
            ->withCount(['assignedTickets as active_workload' => fn (Builder $ticketQuery) => $ticketQuery
                ->whereIn('status', ['open', 'in_progress', 'waiting_customer'])])
            ->orderBy('name')
            ->orderBy('id');

        if ($search !== '') {
            $query->where(fn (Builder $userQuery) => $userQuery
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%'));
        }

        return $query->paginate(25)->withQueryString();
    }

    /**
     * @return array{user: User, profile: AgentProfile, token: string, expires_at: CarbonImmutable}
     */
    public function createInvitedAgent(User $actor, string $name, string $email, bool $autoAssignmentEnabled): array
    {
        $this->authorizeManagement($actor);

        $token = Str::random(64);
        $expiresAt = CarbonImmutable::now()->addMinutes((int) config('ticketing.agent_invitation_expire_minutes', 1440));

        return DB::transaction(function () use ($actor, $name, $email, $autoAssignmentEnabled, $token, $expiresAt): array {
            // Coordinate with assignment workers so a partially created profile cannot enter selection.
            $this->assignments->lockCoordinator();
            $role = $this->activeAgentRole(lock: true);

            $user = User::query()->create([
                'name' => trim($name),
                'email' => mb_strtolower(trim($email)),
                'password' => Str::random(64),
            ]);
            $user->forceFill([
                'is_active' => false,
                'created_by' => $actor->getKey(),
            ])->save();
            $user->assignRole($role);

            $profile = AgentProfile::query()->forceCreate([
                'user_id' => $user->getKey(),
                'auto_assignment_enabled' => $autoAssignmentEnabled,
                'is_active' => false,
                'invitation_token_hash' => hash('sha256', $token),
                'invitation_expires_at' => $expiresAt,
                'created_by' => $actor->getKey(),
            ]);

            return ['user' => $user, 'profile' => $profile, 'token' => $token, 'expires_at' => $expiresAt];
        }, attempts: 3);
    }

    public function createMissingProfile(User $actor, int $agentId): AgentProfile
    {
        $this->authorizeManagement($actor);

        return DB::transaction(function () use ($actor, $agentId): AgentProfile {
            $this->assignments->lockCoordinator();
            $agent = User::query()->whereKey($agentId)->lockForUpdate()->firstOrFail();

            if (! in_array('agent', $agent->tyroRoleSlugs(), true)) {
                throw ValidationException::withMessages(['agent' => 'Only an active agent account can receive an agent profile.']);
            }

            $existingProfile = AgentProfile::withTrashed()->where('user_id', $agentId)->lockForUpdate()->first();
            if ($existingProfile !== null) {
                throw ValidationException::withMessages(['agent' => 'This agent already has a profile.']);
            }

            return AgentProfile::query()->forceCreate([
                'user_id' => $agentId,
                'auto_assignment_enabled' => false,
                'is_active' => $agent->is_active,
                'created_by' => $actor->getKey(),
            ]);
        }, attempts: 3);
    }

    /** @return array{user: User, profile: AgentProfile, token: string, expires_at: CarbonImmutable} */
    public function resendInvitation(User $actor, int $agentId): array
    {
        $this->authorizeManagement($actor);

        $token = Str::random(64);
        $expiresAt = CarbonImmutable::now()->addMinutes((int) config('ticketing.agent_invitation_expire_minutes', 1440));

        return DB::transaction(function () use ($actor, $agentId, $token, $expiresAt): array {
            $this->assignments->lockCoordinator();
            $profile = AgentProfile::query()->where('user_id', $agentId)->lockForUpdate()->firstOrFail();
            $agent = User::query()->whereKey($agentId)->lockForUpdate()->firstOrFail();

            if ($profile->invitation_accepted_at !== null || $profile->invitation_token_hash === null
                || ! in_array('agent', $agent->tyroRoleSlugs(), true)) {
                throw ValidationException::withMessages(['agent' => 'This agent does not have a pending invitation.']);
            }

            $profile->forceFill([
                'invitation_token_hash' => hash('sha256', $token),
                'invitation_expires_at' => $expiresAt,
                'updated_by' => $actor->getKey(),
            ])->save();

            return ['user' => $agent, 'profile' => $profile, 'token' => $token, 'expires_at' => $expiresAt];
        }, attempts: 3);
    }

    public function invitationProfile(string $token): ?AgentProfile
    {
        $profile = AgentProfile::query()
            ->with('user')
            ->where('invitation_token_hash', hash('sha256', $token))
            ->where('invitation_expires_at', '>', now())
            ->whereNull('invitation_accepted_at')
            ->first();

        if ($profile === null || $profile->user === null || $profile->user->trashed()
            || $profile->user->is_active || $profile->is_active) {
            return null;
        }

        return $profile;
    }

    public function completeInvitation(string $token, string $password): void
    {
        $tokenHash = hash('sha256', $token);

        DB::transaction(function () use ($tokenHash, $password): void {
            $profile = AgentProfile::query()
                ->where('invitation_token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($profile === null
                || $profile->invitation_expires_at === null
                || $profile->invitation_expires_at->isPast()
                || $profile->invitation_accepted_at !== null) {
                throw ValidationException::withMessages(['token' => 'This invitation link is invalid or has expired. Request a new invitation from your administrator.']);
            }

            $agent = User::query()->whereKey($profile->user_id)->lockForUpdate()->firstOrFail();
            if (! in_array('agent', $agent->tyroRoleSlugs(), true) || $agent->is_active || $profile->is_active) {
                throw ValidationException::withMessages(['token' => 'This invitation is no longer available. Contact your administrator.']);
            }

            $this->activeAgentRole(lock: true);
            $acceptedAt = CarbonImmutable::now();

            $agent->forceFill([
                'password' => $password,
                'is_active' => true,
                'email_verified_at' => $acceptedAt,
                'authorization_version' => $agent->authorization_version + 1,
                'remember_token' => null,
            ])->save();
            $profile->forceFill([
                'is_active' => true,
                'invitation_token_hash' => null,
                'invitation_expires_at' => null,
                'invitation_accepted_at' => $acceptedAt,
            ])->save();
        }, attempts: 3);
    }

    public function setActive(User $actor, int $agentId, bool $isActive): void
    {
        $this->authorizeManagement($actor);
        $agent = User::query()->whereKey($agentId)->firstOrFail();

        if (! in_array('agent', $agent->tyroRoleSlugs(), true) || $agent->agentProfile === null) {
            throw ValidationException::withMessages(['agent' => 'Choose an account with an agent profile.']);
        }

        if ($isActive && $agent->agentProfile->invitation_token_hash !== null) {
            throw ValidationException::withMessages(['agent' => 'The agent must accept the email invitation before activation.']);
        }

        $this->userLifecycle->setActive($agent, $isActive, $actor);
    }

    private function activeAgentRole(bool $lock = false): Role
    {
        $query = Role::query()
            ->where('name', 'agent')
            ->where('guard_name', 'web')
            ->active();

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function authorizeManagement(User $actor): void
    {
        if (! in_array('admin', $actor->tyroRoleSlugs(), true) || ! $this->permissions->allows($actor, 'agents.manage')) {
            throw new AuthorizationException;
        }
    }
}
