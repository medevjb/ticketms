@extends('tyro-dashboard::layouts.admin')
@section('title', 'Users')
@section('breadcrumb')<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span><span>Users</span>@endsection
@push('styles')
<style>
    .users-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:14px 18px; }
    .users-filters,.users-bulk-actions { display:flex; align-items:center; gap:8px; margin:0; }
    .users-filters { flex:1 1 440px; min-width:0; }
    .users-filters .users-search { width:240px; min-width:140px; flex:1; max-width:320px; }
    .users-filters .users-status { width:130px; }
    .users-bulk-actions .form-input { width:160px; }
    .users-toolbar .form-input { min-height:36px; padding:7px 10px; font-size:12px; }
    .users-toolbar .btn { min-height:36px; padding:8px 12px; font-size:12px; }
    @media(max-width:600px) { .users-filters { flex-wrap:wrap; flex-basis:100%; } .users-filters .users-search { max-width:none; } .users-bulk-actions { margin-left:auto; } }
</style>
@endpush
@section('content')
<div class="page-header"><div class="page-header-row"><div><h1 class="page-title">Users</h1><p class="page-description">{{ $mayManageUsers ? 'Find accounts, manage contact details, and review customer tickets.' : 'Find customer profiles and review tickets assigned to you.' }}</p></div>@if($mayManageUsers)<a class="btn btn-primary" href="{{ route('dashboard.users.create') }}">Add customer</a>@endif</div></div>
<div class="card" style="margin-bottom:14px">
    <div class="card-body users-toolbar">
        <form method="GET" action="{{ route('dashboard.users.index') }}" class="users-filters">
            <input type="search" class="form-input users-search" id="search" name="search" value="{{ $filters['search'] }}" placeholder="Search name or email" maxlength="150" aria-label="Search users">
            <select class="form-input users-status" id="status" name="status" aria-label="Account status">@foreach(($mayManageUsers ? ['active'=>'Enabled','inactive'=>'Disabled','archived'=>'Archived','all'=>'All'] : ['active'=>'Enabled','inactive'=>'Disabled','all'=>'All']) as $value=>$label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select>
            <button class="btn btn-secondary" type="submit">Filter</button>
            <a class="btn btn-ghost" href="{{ route('dashboard.users.index') }}">Reset</a>
        </form>
        @if($mayManageUsers)
        <form method="POST" action="{{ route('dashboard.users.bulk') }}" id="users-bulk-form" class="users-bulk-actions">
            @csrf
            <select id="user-action" name="action" class="form-input" aria-label="Selected account action" required><option value="">Bulk action</option><option value="enable">Enable</option><option value="disable">Disable</option><option value="archive">Archive</option><option value="restore">Restore</option></select>
            <button class="btn btn-secondary" type="submit">Apply</button>
        </form>
        @endif
    </div>
</div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr>@if($mayManageUsers)<th scope="col">Select</th>@endif<th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Verification</th><th scope="col">Actions</th></tr></thead><tbody>
@forelse($users as $account)<tr>@if($mayManageUsers)<td><input type="checkbox" form="users-bulk-form" name="selected_ids[]" value="{{ $account->id }}" aria-label="Select {{ $account->name }}"></td>@endif<td>@if($account->trashed()){{ $account->name }}@else<a href="{{ route('dashboard.users.show', $account) }}"><span title="{{ $account->name }}">{{ str($account->name)->limit(36) }}</span></a>@endif</td><td><span title="{{ $account->email }}">{{ str($account->email)->limit(40) }}</span></td><td><span title="{{ $account->roles->pluck('name')->join(', ') ?: 'No role' }}">{{ str($account->roles->pluck('name')->join(', ') ?: 'No role')->limit(36) }}</span></td><td><span class="badge badge-{{ $account->is_active ? 'success' : 'warning' }}">{{ $account->trashed() ? 'Archived' : ($account->is_active ? 'Enabled' : 'Disabled') }}</span></td><td><span title="{{ $account->email_verified_at ? 'Verified' : 'Unverified' }}">{{ str($account->email_verified_at ? 'Verified' : 'Unverified')->limit(40) }}</span></td><td><div class="admin-row-actions">@if(!$account->trashed())<a class="btn btn-sm btn-ghost admin-icon-button" href="{{ route('dashboard.users.show', $account) }}" title="View" aria-label="View">@include('admin.action-icon', ['icon' => 'view'])</a>@if($mayManageUsers)<a class="btn btn-sm btn-ghost admin-icon-button" href="{{ route('dashboard.users.edit', $account) }}" title="Edit" aria-label="Edit">@include('admin.action-icon', ['icon' => 'edit'])</a>@endif @else<span class="page-description">Select to restore</span>@endif</div></td></tr>
@empty<tr><td colspan="{{ $mayManageUsers ? 7 : 6 }}">No users match these filters.</td></tr>@endforelse
</tbody></table></div><div class="card-body">{{ $users->links() }}</div></div>
@endsection
