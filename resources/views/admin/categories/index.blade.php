@extends('tyro-dashboard::layouts.admin')

@section('title', 'Categories')

@section('breadcrumb')
<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a>
<span class="breadcrumb-separator">/</span>
<span>Categories</span>
@endsection

@section('content')
<div class="page-header">
    <div class="page-header-row">
        <div>
            <h1 class="page-title">Categories</h1>
            <p class="page-description">Manage ticket categories. Disabled or archived categories stay attached to existing tickets and are excluded from new selections.</p>
        </div>
        <a href="{{ route('dashboard.categories.create') }}" class="btn btn-primary">Create category</a>
    </div>
</div>

@if(session('bulk_failures'))
    <div class="alert alert-warning" role="alert">
        <strong>Some categories could not be updated:</strong>
        <ul>
            @foreach(session('bulk_failures') as $failure)
                <li>Category #{{ $failure['id'] }}: {{ $failure['message'] }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card" style="margin-bottom: 1rem;">
    <div class="card-body">
        <form action="{{ route('dashboard.categories.index') }}" method="GET">
            <div class="filters-bar" style="flex-wrap: wrap; gap: 0.75rem;">
                <div class="search-box" style="min-width: 220px; flex: 1;">
                    <input type="search" name="search" class="form-input" aria-label="Search categories" placeholder="Search name or slug" value="{{ $filters['search'] }}">
                </div>
                <div class="filter-group">
                    <label for="status" class="filter-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        @foreach(['active' => 'Enabled', 'inactive' => 'Disabled', 'archived' => 'Archived', 'all' => 'All categories'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary">Filter</button>
                @if($filters['search'] !== '' || $filters['status'] !== 'active')
                    <a href="{{ route('dashboard.categories.index') }}" class="btn btn-ghost">Clear</a>
                @endif
            </div>
        </form>
    </div>
</div>

<div class="card">
    <form action="{{ route('dashboard.categories.bulk') }}" method="POST" id="category-bulk-form">
        @csrf
        <input type="hidden" name="operation_key" value="{{ old('operation_key', (string) \Illuminate\Support\Str::uuid()) }}">
        <div class="card-body" style="border-bottom: 1px solid var(--border); display:flex; align-items:center; gap:.75rem; justify-content:space-between; flex-wrap:wrap;">
            <label style="display:inline-flex; align-items:center; gap:.5rem;">
                <input type="checkbox" id="select-all-categories" aria-label="Select all categories on this page">
                Select all on page
            </label>
            <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
                <label for="bulk-action" class="filter-label">Bulk action</label>
                <select id="bulk-action" name="action" class="form-select" required>
                    <option value="">Choose action</option>
                    <option value="enable">Enable</option>
                    <option value="disable">Disable</option>
                    <option value="archive">Archive</option>
                    <option value="restore">Restore</option>
                </select>
                <button type="submit" class="btn btn-secondary">Apply to selected</button>
            </div>
        </div>
    </form>
    <div class="table-container">
        <table class="table">
            <thead><tr><th></th><th>Name</th><th>Slug</th><th>Status</th><th>Updated</th><th style="text-align:right">Actions</th></tr></thead>
            <tbody>
                @forelse($categories as $category)
                    <tr>
                        <td><input type="checkbox" form="category-bulk-form" name="selected_ids[]" value="{{ $category->id }}" class="category-checkbox" aria-label="Select {{ $category->name }}"></td>
                        <td><strong>{{ $category->name }}</strong>@if($category->description)<div class="page-description">{{ $category->description }}</div>@endif</td>
                        <td><code>{{ $category->slug }}</code></td>
                        <td>
                            @if($category->trashed()) <span class="badge badge-secondary">Archived</span>
                            @elseif($category->is_active) <span class="badge badge-success">Enabled</span>
                            @else <span class="badge badge-warning">Disabled</span> @endif
                        </td>
                        <td>{{ $category->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                        <td style="text-align:right; white-space:nowrap">
                            @if($category->trashed())
                                <form action="{{ route('dashboard.categories.restore', $category->id) }}" method="POST" style="display:inline">@csrf<button class="btn btn-secondary" type="submit">Restore</button></form>
                            @else
                                <a href="{{ route('dashboard.categories.edit', $category) }}" class="btn btn-ghost">Edit</a>
                                <form action="{{ route('dashboard.categories.availability', $category) }}" method="POST" style="display:inline">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $category->is_active ? '0' : '1' }}">
                                    <button class="btn btn-secondary" type="submit">{{ $category->is_active ? 'Disable' : 'Enable' }}</button>
                                </form>
                                <form action="{{ route('dashboard.categories.archive', $category) }}" method="POST" style="display:inline" onsubmit="return confirm('Archive this category? Existing tickets will keep their category reference.')">
                                    @csrf<button class="btn btn-ghost" type="submit">Archive</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center; padding:2rem; color:var(--muted-foreground)">No categories match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $categories->links() }}</div>
</div>

<script>
    document.getElementById('select-all-categories')?.addEventListener('change', (event) => {
        document.querySelectorAll('.category-checkbox').forEach((checkbox) => { checkbox.checked = event.target.checked; });
    });
</script>
@endsection
