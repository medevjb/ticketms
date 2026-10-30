<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LifecycleBulkAction;
use App\Http\Requests\Access\BulkRoleLifecycleRequest;
use App\Http\Requests\Access\BulkUserLifecycleRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\AccessLifecycleBulkService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccessManagementController extends BaseController
{
    public function users(Request $request): View
    {
        $status = in_array($request->query('status'), ['active', 'inactive', 'archived', 'all'], true)
            ? (string) $request->query('status')
            : 'active';
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 150);
        $query = User::query()->withTrashed()->with('roles')->orderBy('name')->orderBy('id');

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'active') {
            $query->whereNull('users.deleted_at')->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->whereNull('users.deleted_at')->where('is_active', false);
        }

        if ($search !== '') {
            $query->where(fn ($builder) => $builder->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        }

        return view('admin.access.users', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'users' => $query->paginate(25)->withQueryString(),
            'filters' => ['status' => $status, 'search' => $search],
        ]));
    }

    public function roles(Request $request): View
    {
        $status = in_array($request->query('status'), ['active', 'inactive', 'archived', 'all'], true)
            ? (string) $request->query('status')
            : 'active';
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 150);
        $query = Role::query()->withTrashed()->with('permissions')->withCount('users')->orderBy('name')->orderBy('id');
        $query->where('name', '!=', 'customer');

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'active') {
            $query->whereNull('access_roles.deleted_at')->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->whereNull('access_roles.deleted_at')->where('is_active', false);
        }

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        return view('admin.access.roles', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'roles' => $query->paginate(25)->withQueryString(),
            'filters' => ['status' => $status, 'search' => $search],
        ]));
    }

    public function bulkUsers(BulkUserLifecycleRequest $request, AccessLifecycleBulkService $bulk): RedirectResponse
    {
        $data = $request->validated();
        $result = $bulk->users($request->user(), LifecycleBulkAction::from($data['action']), $data['operation_key'], array_values(array_map('intval', $data['selected_ids'])));

        return $this->bulkRedirect('dashboard.access.users', 'users', $result);
    }

    public function bulkRoles(BulkRoleLifecycleRequest $request, AccessLifecycleBulkService $bulk): RedirectResponse
    {
        $data = $request->validated();
        $result = $bulk->roles($request->user(), LifecycleBulkAction::from($data['action']), $data['operation_key'], array_values(array_map('intval', $data['selected_ids'])));

        return $this->bulkRedirect('dashboard.access.roles', 'roles', $result);
    }

    /** @param array{succeeded: int, failed: list<array{id: int, message: string}>} $result */
    private function bulkRedirect(string $route, string $label, array $result): RedirectResponse
    {
        $redirect = to_route($route)->with('success', "Bulk action completed for {$result['succeeded']} {$label}.");
        if ($result['failed'] !== []) {
            $redirect->with('bulk_failures', $result['failed']);
        }

        return $redirect;
    }
}
