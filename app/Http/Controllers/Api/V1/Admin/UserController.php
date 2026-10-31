<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\IndexUsersRequest;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserStatusRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\UserLifecycleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(IndexUsersRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = User::query()->with('roles:id,name')->orderBy('name')->orderBy('id');
        $status = $filters['status'] ?? 'active';

        if (in_array($status, ['archived', 'all'], true)) {
            $query->withTrashed();
        }

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status !== 'all') {
            $query->where('is_active', $status === 'active');
        }

        if (($filters['search'] ?? '') !== '') {
            $search = trim($filters['search']);
            $query->where(fn (Builder $users) => $users
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%'));
        }

        return UserResource::collection($query->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $role = Role::query()->where('name', 'customer')->where('guard_name', 'web')->where('is_active', true)->firstOrFail();
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $user->forceFill(['created_by' => $request->user()->getKey()])->save();
            $user->assignRole($role);

            return $user;
        });

        return (new UserResource($user->load('roles:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(int $account): UserResource
    {
        $account = User::query()->withTrashed()->with('roles:id,name')->withCount([
            'ownedTickets',
            'ownedTickets as active_tickets_count' => fn (Builder $tickets) => $tickets->whereIn('status', [
                TicketStatus::Open,
                TicketStatus::InProgress,
                TicketStatus::WaitingForCustomer,
            ]),
        ])->findOrFail($account);

        return new UserResource($account);
    }

    public function update(UpdateUserRequest $request, int $account): UserResource
    {
        $account = User::query()->findOrFail($account);
        $account->fill($request->safe()->only(['name', 'email']));

        if ($account->isDirty('email')) {
            $account->forceFill(['email_verified_at' => null, 'remember_token' => null]);
        }

        $account->forceFill(['updated_by' => $request->user()->getKey()])->save();

        return new UserResource($account->load('roles:id,name'));
    }

    public function setStatus(UpdateUserStatusRequest $request, int $account, UserLifecycleService $lifecycle): UserResource
    {
        $target = User::query()->findOrFail($account);
        $lifecycle->setActive($target, $request->boolean('is_active'), $request->user());

        return new UserResource($target->fresh()->load('roles:id,name'));
    }

    public function archive(Request $request, int $account, UserLifecycleService $lifecycle): JsonResponse
    {
        $target = User::query()->findOrFail($account);
        $lifecycle->archive($target, $request->user());

        return response()->json(['message' => 'User archived.']);
    }

    public function restore(Request $request, int $account, UserLifecycleService $lifecycle): UserResource
    {
        $target = User::query()->withTrashed()->findOrFail($account);

        if (! $target->trashed()) {
            throw ValidationException::withMessages(['user' => 'This user is not archived.']);
        }

        $lifecycle->restore($target, $request->user());

        return new UserResource($target->fresh()->load('roles:id,name'));
    }
}
