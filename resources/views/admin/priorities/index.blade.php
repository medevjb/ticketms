@extends('tyro-dashboard::layouts.admin')

@section('title', 'Priorities')

@section('breadcrumb')
<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span><span>Priorities</span>
@endsection

@push('styles')
<style>
    .priority-filters { display:flex; align-items:flex-end; gap:16px; flex-wrap:wrap; }
    .priority-search { flex:1 1 260px; }
    .priority-status { flex:0 1 200px; min-width:160px; }
    .priority-filter-actions,.priority-row-actions { display:flex; align-items:center; gap:8px; }
    .priority-filters .form-input,.priority-filters .form-select,.priority-filters .btn { min-height:40px; box-sizing:border-box; }
    .priority-bulk-bar { border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; }
    .priority-select-all { display:inline-flex; align-items:center; gap:8px; min-height:40px; white-space:nowrap; }
    .priority-bulk-controls { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
    .priority-bulk-field { width:200px; }
    .priority-bulk-controls .form-select,.priority-bulk-controls .btn { min-height:40px; box-sizing:border-box; }
    .priority-row-actions { justify-content:flex-end; flex-wrap:nowrap; }
    .priority-row-actions form { display:flex; margin:0; }
    .priority-row-actions .btn { white-space:nowrap; }
    .priority-table td { vertical-align:middle; }
    .priority-table .priority-selection { width:44px; text-align:center; }
    .priority-table .priority-order { width:80px; text-align:center; font-variant-numeric:tabular-nums; }
    @media(max-width:640px) {
        .priority-status { flex:1 1 160px; }
        .priority-bulk-controls { width:100%; }
        .priority-bulk-field { flex:1 1 180px; }
    }
</style>
@endpush

@section('content')
<div class="page-header"><div class="page-header-row"><div><h1 class="page-title">Priorities</h1><p class="page-description">Control ticket priority choices and display order. Set resolution targets in the SLA policy; tickets keep the target saved when they were created.</p></div><a href="{{ route('dashboard.priorities.create') }}" class="btn btn-primary">Create priority</a></div></div>


<div class="card" style="margin-bottom:1rem">
    <div class="card-body">
        <form action="{{ route('dashboard.priorities.index') }}" method="GET" class="priority-filters">
            <div class="priority-search">
                <label for="priority-search" class="form-label">Search priorities</label>
                <input id="priority-search" type="search" name="search" class="form-input" placeholder="Search name or slug" value="{{ $filters['search'] }}">
            </div>
            <div class="priority-status">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    @foreach(['active'=>'Enabled','inactive'=>'Disabled','archived'=>'Archived','all'=>'All priorities'] as $value=>$label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="priority-filter-actions">
                <button class="btn btn-secondary" type="submit">Filter</button>
                @if($filters['search'] !== '' || $filters['status'] !== 'active')
                <a class="btn btn-ghost" href="{{ route('dashboard.priorities.index') }}">Clear</a>
                @endif
            </div>
        </form>
    </div>
</div>
<div class="card">
    <form action="{{ route('dashboard.priorities.bulk') }}" method="POST" id="priority-bulk-form">
        @csrf
        <div class="card-body priority-bulk-bar">
            <label class="priority-select-all"><input type="checkbox" id="select-all-priorities">Select all on page</label>
            <div class="priority-bulk-controls">
                <div class="priority-bulk-field">
                    <label for="bulk-action" class="form-label">Bulk action</label>
                    <select id="bulk-action" name="action" class="form-select" required>
                        <option value="">Choose action</option><option value="enable">Enable</option><option value="disable">Disable</option><option value="archive">Archive</option><option value="restore">Restore</option>
                    </select>
                </div>
                <button class="btn btn-secondary" type="submit">Apply to selected</button>
            </div>
        </div>
    </form>
    <div class="table-container"><table class="table priority-table"><thead><tr><th scope="col" class="priority-selection"><span class="sr-only">Select</span></th><th scope="col">Priority</th><th scope="col">Slug</th><th scope="col" class="priority-order">Order</th><th scope="col">Status</th><th style="text-align:right">Actions</th></tr></thead><tbody>
    @forelse($priorities as $priority)<tr><td class="priority-selection"><input type="checkbox" form="priority-bulk-form" name="selected_ids[]" value="{{ $priority->id }}" class="priority-checkbox" aria-label="Select {{ $priority->name }}"></td><td><strong><span title="{{ $priority->name }}">{{ str($priority->name)->limit(36) }}</span></strong></td><td><code><span title="{{ $priority->slug }}">{{ str($priority->slug)->limit(36) }}</span></code></td><td class="priority-order">{{ $priority->sort_order }}</td><td>@if($priority->trashed())<span class="badge badge-secondary">Archived</span>@elseif($priority->is_active)<span class="badge badge-success">Enabled</span>@else<span class="badge badge-warning">Disabled</span>@endif</td><td><div class="priority-row-actions">@if($priority->trashed())<form action="{{ route('dashboard.priorities.restore',$priority->id) }}" method="POST" style="display:inline">@csrf<button class="btn btn-secondary admin-icon-button" type="submit" title="Restore" aria-label="Restore">@include('admin.action-icon', ['icon' => 'restore'])</button></form>@else<a href="{{ route('dashboard.priorities.edit',$priority) }}" class="btn btn-ghost admin-icon-button" title="Edit" aria-label="Edit">@include('admin.action-icon', ['icon' => 'edit'])</a><form action="{{ route('dashboard.priorities.availability',$priority) }}" method="POST" style="display:inline">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $priority->is_active ? '0' : '1' }}"><button class="btn btn-secondary admin-icon-button" type="submit" title="{{ $priority->is_active ? 'Disable' : 'Enable' }}" aria-label="{{ $priority->is_active ? 'Disable' : 'Enable' }}">@include('admin.action-icon', ['icon' => 'availability'])</button></form><form action="{{ route('dashboard.priorities.archive',$priority) }}" method="POST" style="display:inline">@csrf<button class="btn btn-ghost admin-icon-button" type="submit" title="Archive" aria-label="Archive">@include('admin.action-icon', ['icon' => 'archive'])</button></form>@endif</div></td></tr>
    @empty<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted-foreground)">No priorities match these filters.</td></tr>@endforelse
    </tbody></table></div><div class="card-body">{{ $priorities->links() }}</div>
</div>
<script>document.getElementById('select-all-priorities')?.addEventListener('change',(event)=>{document.querySelectorAll('.priority-checkbox').forEach((checkbox)=>{checkbox.checked=event.target.checked;});});</script>
@endsection
