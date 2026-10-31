<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketStatus;
use App\Http\Requests\Access\SaveUserRequest;
use App\Models\Role;
use App\Models\User;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UserController extends BaseController
{
    public function index(Request $request): View
    {
        $manager = $this->mayManage($request->user());
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 150);
        $status = in_array($request->query('status'), ($manager ? ['active', 'inactive', 'archived', 'all'] : ['active', 'inactive', 'all']), true)
            ? $request->query('status') : 'active';
        $query = User::query()->with('roles')->orderBy('name')->orderBy('id');
        if (! $manager) {
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'customer'))
                ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', ['admin', 'agent']));
        }
        if ($manager && in_array($status, ['archived', 'all'], true)) {
            $query->withTrashed();
        }
        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status !== 'all') {
            $query->where('is_active', $status === 'active');
        }
        if ($search !== '') {
            $query->where(fn (Builder $users) => $users->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        }

        return view('admin.users-index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'users' => $query->paginate(25)->withQueryString(),
            'filters' => compact('search', 'status'),
            'mayManageUsers' => $manager,
        ]));
    }

    public function show(Request $request, User $user): View
    {
        $user->load('roles');
        $manager = $this->mayManage($request->user());
        $roles = $user->roles->pluck('name')->all();
        abort_unless($manager || (in_array('customer', $roles, true) && array_intersect(['admin', 'agent'], $roles) === []), 404);
        $tickets = $user->ownedTickets();
        if (! $manager) {
            $tickets->where('assigned_agent_id', $request->user()->getKey());
        }

        return view('admin.users-show', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'account' => $user,
            'mayManageUsers' => $manager,
            'ticketCount' => (clone $tickets)->count(),
            'activeTicketCount' => (clone $tickets)->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress, TicketStatus::WaitingForCustomer])->count(),
            'tickets' => $tickets->latest('created_at')->latest('id')->paginate(15),
        ]));
    }

    public function create(): View
    {
        return view('admin.users-form', $this->getViewData(['dashboardRoute' => DashboardRoute::class, 'account' => null]));
    }

    public function store(SaveUserRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $role = Role::query()->where('name', 'customer')->where('guard_name', 'web')->where('is_active', true)->firstOrFail();
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $user->forceFill(['created_by' => $request->user()->getKey()])->save();
            $user->assignRole($role);

            return $user;
        });

        return to_route('dashboard.users.show', $user)->with('success', 'Customer account created. Email verification is still required.');
    }

    public function edit(User $user): View
    {
        return view('admin.users-form', $this->getViewData(['dashboardRoute' => DashboardRoute::class, 'account' => $user]));
    }

    public function update(SaveUserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->safe()->only(['name', 'email']));
        if ($user->isDirty('email')) {
            $user->forceFill(['email_verified_at' => null, 'remember_token' => null]);
        }
        $user->forceFill(['updated_by' => $request->user()->getKey()])->save();

        return to_route('dashboard.users.show', $user)->with('success', 'User details updated.');
    }

    private function mayManage(User $actor): bool
    {
        return in_array('admin', $actor->tyroRoleSlugs(), true) && $actor->can('users.manage');
    }
}
