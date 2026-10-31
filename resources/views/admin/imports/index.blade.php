@extends('tyro-dashboard::layouts.admin')

@section('title', 'Ticket imports')

@section('breadcrumb')
<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a>
<span class="breadcrumb-separator">/</span>
<span>Ticket imports</span>
@endsection

@section('content')
<div class="page-header">
    <div class="page-header-row">
        <div>
            <h1 class="page-title">Ticket imports</h1>
            <p class="page-description">Upload a UTF-8 CSV to create tickets for existing verified customers. Rows are processed in the background; an import never updates an existing ticket.</p>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:1rem;">
    <div class="card-body">
        <h2 style="margin-top:0;">Start an import</h2>
        <p class="page-description">Maximum file size: 100 MiB. Use the exact columns <code>external_id,customer_email,subject,description,priority,category</code>. Quote fields containing commas or line breaks.</p>
        <form action="{{ route('dashboard.ticket-imports.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="form-group">
                <label for="source" class="form-label">Source system</label>
                <input id="source" name="source" class="form-input" maxlength="64" required value="{{ old('source') }}" aria-describedby="source-help">
                <small id="source-help" class="page-description">Use a stable identifier such as <code>crm</code>. This key is used with each external ID to prevent duplicate ticket creation.</small>

            </div>
            <div class="form-group">
                <label for="file" class="form-label">CSV file</label>
                <input id="file" name="file" type="file" class="form-input" accept=".csv,text/csv" required>

            </div>
            <button type="submit" class="btn btn-primary">Queue import</button>
        </form>
    </div>
</div>

<div class="card" style="margin-bottom:1rem;">
    <div class="card-body">
        <form action="{{ route('dashboard.ticket-imports.index') }}" method="GET" class="filters-bar" style="flex-wrap:wrap;gap:.75rem;">
            <div class="filter-group">
                <label for="status" class="filter-label">Show runs</label>
                <select id="status" name="status" class="form-select">
                    @foreach(['all' => 'All runs', 'queued' => 'Queued', 'running' => 'Running', 'paused' => 'Paused', 'completed' => 'Completed', 'completed_with_errors' => 'Completed with errors', 'failed' => 'Failed', 'cancelled' => 'Cancelled', 'archived' => 'Archived'] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Filter</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-container">
        <table class="table">
            <thead><tr><th>Run</th><th>Source</th><th>Status</th><th>Processed</th><th>Created</th><th>Invalid</th><th>Duplicates</th><th>Uploaded by</th><th>Created</th></tr></thead>
            <tbody>
                @forelse($runs as $run)
                    <tr>
                        <td><a href="{{ route('dashboard.ticket-imports.show', $run->id) }}">#{{ $run->id }}</a></td>
                        <td><code><span title="{{ $run->source }}">{{ str($run->source)->limit(36) }}</span></code></td>
                        <td>{{ str($run->processing_status->value)->replace('_', ' ')->title() }}</td>
                        <td>{{ number_format($run->processed_rows) }}</td>
                        <td>{{ number_format($run->created_rows) }}</td>
                        <td>{{ number_format($run->invalid_rows) }}</td>
                        <td>{{ number_format($run->duplicate_rows) }}</td>
                        <td><span title="{{ $run->uploader?->name ?? 'Unavailable' }}">{{ str($run->uploader?->name ?? 'Unavailable')->limit(36) }}</span></td>
                        <td>{{ $run->created_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--muted-foreground)">No import runs match this filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $runs->links() }}</div>
</div>
@endsection
