@extends('tyro-dashboard::layouts.admin')

@section('title', 'Import run #'.$run->id)

@section('breadcrumb')
<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a>
<span class="breadcrumb-separator">/</span>
<a href="{{ route('dashboard.ticket-imports.index') }}">Ticket imports</a>
<span class="breadcrumb-separator">/</span>
<span>Run #{{ $run->id }}</span>
@endsection

@section('content')
<div class="page-header">
    <div class="page-header-row">
        <div>
            <h1 class="page-title">Import run #{{ $run->id }}</h1>
            <p class="page-description">Source <code>{{ $run->source }}</code> · uploaded by {{ $run->uploader?->name ?? 'Unavailable' }} · {{ $run->created_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('Y-m-d H:i') }}</p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <a href="{{ route('dashboard.ticket-imports.show', $run->id) }}" class="btn btn-secondary">Refresh progress</a>
            @can('imports.download_errors')
                @if($run->invalid_rows > 0)
                    <a href="{{ route('dashboard.ticket-imports.errors', $run->id) }}" class="btn btn-secondary">Download error CSV</a>
                @endif
            @endcan
        </div>
    </div>
</div>

@if($run->trashed())
    <div class="alert alert-warning" role="status">This run is archived. Restore it to manage its state.</div>
@endif

<div class="card" style="margin-bottom:1rem;">
    <div class="card-body">
        <h2 style="margin-top:0;">{{ str($run->processing_status->value)->replace('_', ' ')->title() }}</h2>
        <p class="page-description">Processed rows include created, invalid, and duplicate records. Each valid row is committed independently.</p>
        <div class="table-container">
            <table class="table"><tbody>
                <tr><th>Processed</th><td>{{ number_format($run->processed_rows) }}</td></tr>
                <tr><th>Created tickets</th><td>{{ number_format($run->created_rows) }}</td></tr>
                <tr><th>Invalid rows</th><td>{{ number_format($run->invalid_rows) }}</td></tr>
                <tr><th>Duplicate source IDs</th><td>{{ number_format($run->duplicate_rows) }}</td></tr>
                <tr><th>Started</th><td>{{ $run->started_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('Y-m-d H:i:s') ?? 'Not started' }}</td></tr>
                <tr><th>Completed</th><td>{{ $run->completed_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('Y-m-d H:i:s') ?? 'In progress' }}</td></tr>
                @if($run->failure_code)<tr><th>Failure code</th><td><code><span title="{{ $run->failure_code }}">{{ str($run->failure_code)->limit(36) }}</span></code></td></tr>@endif
            </tbody></table>
        </div>

        @if(!$run->trashed())
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem;">
                @if(in_array($run->processing_status->value, ['queued', 'running'], true))
                    <form action="{{ route('dashboard.ticket-imports.control', $run->id) }}" method="POST">@csrf<input type="hidden" name="action" value="pause"><button class="btn btn-secondary" type="submit">Pause after current row</button></form>
                @endif
                @if($run->processing_status->value === 'paused')
                    <form action="{{ route('dashboard.ticket-imports.control', $run->id) }}" method="POST">@csrf<input type="hidden" name="action" value="resume"><button class="btn btn-primary" type="submit">Resume import</button></form>
                @endif
                @if(in_array($run->processing_status->value, ['queued', 'running', 'paused'], true))
                    <form action="{{ route('dashboard.ticket-imports.control', $run->id) }}" method="POST">@csrf<input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Cancel import</button></form>
                @endif
                @if(in_array($run->processing_status->value, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true))
                    <form action="{{ route('dashboard.ticket-imports.control', $run->id) }}" method="POST">@csrf<input type="hidden" name="action" value="archive"><button class="btn btn-ghost" type="submit">Archive history</button></form>
                @endif
            </div>
        @else
            <form action="{{ route('dashboard.ticket-imports.control', $run->id) }}" method="POST" style="margin-top:1rem;">@csrf<input type="hidden" name="action" value="restore"><button class="btn btn-secondary" type="submit">Restore history</button></form>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title">Recent row results</h2></div>
    <div class="table-container">
        <table class="table">
            <thead><tr><th>CSV row</th><th>External ID</th><th>Result</th><th>Ticket</th><th>Field codes</th></tr></thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td>{{ $row->row_number }}</td>
                        <td><code><span title="{{ $row->external_id ?? '—' }}">{{ str($row->external_id ?? '—')->limit(36) }}</span></code></td>
                        <td>{{ str($row->result->value)->title() }}</td>
                        <td>{{ $row->ticket_id ? '#'.$row->ticket_id : '—' }}</td>
                        <td><span title="{{ implode(', ', $row->error_codes ?? []) ?: '—' }}">{{ str(implode(', ', $row->error_codes ?? []) ?: '—')->limit(100) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted-foreground)">No CSV rows have been processed yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $rows->links() }}</div>
</div>
@endsection
