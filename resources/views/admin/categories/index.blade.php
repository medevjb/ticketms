@extends('tyro-dashboard::layouts.admin')

@section('title', 'Categories')

@section('breadcrumb')
<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a>
<span class="breadcrumb-separator">/</span>
<span>Categories</span>
@endsection

@push('styles')
<style>
    .category-filters { display:flex; align-items:flex-end; gap:16px; flex-wrap:wrap; }
    .category-search { flex:1 1 260px; }
    .category-status { flex:0 1 200px; min-width:160px; }
    .category-filter-actions,.category-row-actions { display:flex; align-items:center; gap:8px; }
    .category-filters .form-input,.category-filters .form-select,.category-filters .btn { min-height:40px; box-sizing:border-box; }
    .category-bulk-bar { border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; }
    .category-select-all { display:inline-flex; align-items:center; gap:8px; min-height:40px; white-space:nowrap; }
    .category-bulk-controls { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
    .category-bulk-field { width:200px; }
    .category-bulk-controls .form-select,.category-bulk-controls .btn { min-height:40px; box-sizing:border-box; }
    .category-row-actions { justify-content:flex-end; flex-wrap:nowrap; }
    .category-row-actions form { display:flex; margin:0; }
    .category-row-actions .btn { white-space:nowrap; }
    .category-table td { vertical-align:middle; }
    .category-table .category-selection { width:44px; text-align:center; }
    .category-table td:nth-child(2) { min-width:200px; }
    .category-table td:nth-child(2) .page-description { max-width:440px; overflow-wrap:anywhere; }
    @media(max-width:640px) {
        .category-status { flex:1 1 160px; }
        .category-bulk-controls { width:100%; }
        .category-bulk-field { flex:1 1 180px; }
    }
</style>
@endpush

@section('content')
<div class="page-header">
    <div class="page-header-row">
        <div>
            <h1 class="page-title">Categories</h1>
            <p class="page-description">Manage ticket categories. Disabled or archived categories stay attached to existing tickets and are excluded from new selections.</p>
        </div>
        <a href="{{ route('dashboard.categories.create') }}" class="btn btn-primary" data-category-create>Create category</a>
    </div>
</div>



<div class="card" style="margin-bottom: 1rem;">
    <div class="card-body">
        <form action="{{ route('dashboard.categories.index') }}" method="GET">
            <div class="category-filters">
                <div class="category-search">
                    <label for="category-search" class="form-label">Search categories</label>
                    <input id="category-search" type="search" name="search" class="form-input" aria-label="Search categories" placeholder="Search name or slug" value="{{ $filters['search'] }}">
                </div>
                <div class="category-status">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        @foreach(['active' => 'Enabled', 'inactive' => 'Disabled', 'archived' => 'Archived', 'all' => 'All categories'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="category-filter-actions">
                <button type="submit" class="btn btn-secondary">Filter</button>
                @if($filters['search'] !== '' || $filters['status'] !== 'active')
                    <a href="{{ route('dashboard.categories.index') }}" class="btn btn-ghost">Clear</a>
                @endif
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <form action="{{ route('dashboard.categories.bulk') }}" method="POST" id="category-bulk-form">
        @csrf

        <div class="card-body category-bulk-bar">
            <label class="category-select-all">
                <input type="checkbox" id="select-all-categories" aria-label="Select all categories on this page">
                Select all on page
            </label>
            <div class="category-bulk-controls">
                <div class="category-bulk-field">
                <label for="bulk-action" class="form-label">Bulk action</label>
                <select id="bulk-action" name="action" class="form-select" required>
                    <option value="">Choose action</option>
                    <option value="enable">Enable</option>
                    <option value="disable">Disable</option>
                    <option value="archive">Archive</option>
                    <option value="restore">Restore</option>
                </select>
                </div>
                <button type="submit" class="btn btn-secondary">Apply to selected</button>
            </div>
        </div>
    </form>
    <div class="table-container">
        <table class="table category-table">
            <thead><tr><th scope="col" class="category-selection" aria-label="Select categories"></th><th>Name</th><th>Slug</th><th>Status</th><th>Updated</th><th style="text-align:right">Actions</th></tr></thead>
            <tbody>
                @forelse($categories as $category)
                    <tr>
                        <td class="category-selection"><input type="checkbox" form="category-bulk-form" name="selected_ids[]" value="{{ $category->id }}" class="category-checkbox" aria-label="Select {{ $category->name }}"></td>
                        <td><strong><span title="{{ $category->name }}">{{ str($category->name)->limit(36) }}</span></strong>@if($category->description)<div class="page-description"><span title="{{ $category->description }}">{{ str($category->description)->limit(100) }}</span></div>@endif</td>
                        <td><code><span title="{{ $category->slug }}">{{ str($category->slug)->limit(36) }}</span></code></td>
                        <td>
                            @if($category->trashed()) <span class="badge badge-secondary">Archived</span>
                            @elseif($category->is_active) <span class="badge badge-success">Enabled</span>
                            @else <span class="badge badge-warning">Disabled</span> @endif
                        </td>
                        <td>{{ $category->updated_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('Y-m-d H:i') }}</td>
                        <td><div class="category-row-actions">
                            @if($category->trashed())
                                <form action="{{ route('dashboard.categories.restore', $category->id) }}" method="POST" style="display:inline">@csrf<button class="btn btn-secondary admin-icon-button" type="submit" title="Restore" aria-label="Restore">@include('admin.action-icon', ['icon' => 'restore'])</button></form>
                            @else
                                <a data-category-edit="{{ json_encode(['id'=>$category->id, 'name'=>$category->name, 'slug'=>$category->slug, 'description'=>$category->description]) }}" href="{{ route('dashboard.categories.edit', $category) }}" class="btn btn-ghost admin-icon-button" title="Edit" aria-label="Edit">@include('admin.action-icon', ['icon' => 'edit'])</a>
                                <form action="{{ route('dashboard.categories.availability', $category) }}" method="POST" style="display:inline">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $category->is_active ? '0' : '1' }}">
                                    <button class="btn btn-secondary admin-icon-button" type="submit" title="{{ $category->is_active ? 'Disable' : 'Enable' }}" aria-label="{{ $category->is_active ? 'Disable' : 'Enable' }}">@include('admin.action-icon', ['icon' => 'availability'])</button>
                                </form>
                                <form action="{{ route('dashboard.categories.archive', $category) }}" method="POST" style="display:inline">
                                    @csrf<button class="btn btn-ghost admin-icon-button" type="submit" title="Archive" aria-label="Archive">@include('admin.action-icon', ['icon' => 'archive'])</button>
                                </form>
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center; padding:2rem; color:var(--muted-foreground)">No categories match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $categories->links() }}</div>
</div>

<dialog id="category-editor" class="app-dialog category-editor" aria-labelledby="category-editor-title" @if($openCategoryEditor) open @endif>
    <header class="app-dialog-header"><div><span class="app-dialog-kicker">Ticket organization</span><h2 id="category-editor-title">{{ $editingCategory ? 'Edit category' : 'Create category' }}</h2></div><button class="app-dialog-close" type="button" data-category-close aria-label="Close category editor">×</button></header>
    <form id="category-editor-form" method="POST" action="{{ $editingCategory ? route('dashboard.categories.update', $editingCategory) : route('dashboard.categories.store') }}">
        @csrf<input type="hidden" name="_method" value="{{ $editingCategory ? 'PUT' : 'POST' }}"><input type="hidden" name="category_form" value="1"><input type="hidden" name="category_modal_id" value="{{ $editingCategory?->id }}"><input type="hidden" name="slug_mode" value="{{ old('slug_mode', 'auto') }}">
        <div class="app-dialog-body">@include('admin.categories._form', ['category'=>$editingCategory])</div>
        <footer class="app-dialog-footer"><button type="button" class="btn btn-ghost" data-category-close>Cancel</button><button type="submit" class="btn btn-primary" data-category-submit>{{ $editingCategory ? 'Save changes' : 'Create category' }}</button></footer>
    </form>
</dialog>
<script>
    document.getElementById('select-all-categories')?.addEventListener('change', (event) => {
        document.querySelectorAll('.category-checkbox').forEach((checkbox) => { checkbox.checked = event.target.checked; });
    });
</script>
@endsection

@push('scripts')
@vite('resources/js/category-editor.ts')
@endpush
