@extends('tyro-dashboard::layouts.admin')
@section('title', 'Ticket analytics')
@section('breadcrumb')<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span><span>Analytics</span>@endsection
@push('styles')
<style>
    .analytics-page { display:grid; gap:20px; }
    .analytics-page .page-header,.analytics-page .card { margin:0; }
    .analytics-page .card-body { padding:20px; }
    .analytics-header,.analytics-section-heading,.analytics-filter-bar { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
    .analytics-page h2 { margin:0; font-size:15px; font-weight:650; }
    .analytics-note { margin:5px 0 0; font-size:12px; line-height:1.6; color:var(--muted-foreground); }
    .analytics-filter-fields,.analytics-presets,.analytics-table-controls { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; }
    .analytics-filter-fields .form-input { width:160px; }
    .analytics-page .form-input,.analytics-page .btn { min-height:36px; font-size:12px; }
    .analytics-page .form-label { font-size:11px; }
    .analytics-presets { align-items:center; }
    .analytics-presets .btn { min-height:32px; padding:6px 10px; }
    .analytics-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; }
    .analytics-kpi { padding:20px; border:1px solid var(--border); border-radius:12px; background:var(--card); }
    .analytics-kpi-label { font-size:12px; color:var(--muted-foreground); }
    .analytics-kpi-value { margin:12px 0 6px; color:var(--foreground); font-size:30px; line-height:1; font-weight:650; letter-spacing:-.04em; font-variant-numeric:tabular-nums; }
    .analytics-snapshot { display:grid; grid-template-columns:repeat(auto-fit,minmax(125px,1fr)); gap:0; margin-top:18px; }
    .analytics-snapshot-item { display:block; padding:12px 16px; border-left:1px solid var(--border); text-decoration:none; color:inherit; }
    .analytics-snapshot-item:first-child { border-left:0; padding-left:0; }
    .analytics-snapshot-item span { display:block; font-size:11px; color:var(--muted-foreground); }
    .analytics-snapshot-item strong { display:block; margin-top:8px; font-size:22px; font-weight:650; font-variant-numeric:tabular-nums; }
    .analytics-snapshot-item:hover { background:var(--muted); border-radius:6px; }
    .analytics-period-tag { padding:5px 8px; border:1px solid var(--border); border-radius:6px; font-size:10px; color:var(--muted-foreground); }
    .analytics-table-controls { align-items:center; }
    .analytics-table-controls label { display:flex; align-items:center; gap:7px; font-size:11px; color:var(--muted-foreground); }
    .analytics-table-controls .form-input { width:auto; padding:6px 8px; }
    .analytics-daily-summary { display:flex; gap:24px; flex-wrap:wrap; margin:18px 0; padding:12px 0; border-top:1px solid var(--border); border-bottom:1px solid var(--border); }
    .analytics-daily-summary span { font-size:11px; color:var(--muted-foreground); }
    .analytics-daily-summary strong { margin-right:5px; font-size:15px; color:var(--foreground); font-variant-numeric:tabular-nums; }
    .analytics-page .table th,.analytics-page .table td { vertical-align:middle; }
    .analytics-number { font-variant-numeric:tabular-nums; font-weight:600; }
    .analytics-zero { color:var(--muted-foreground); }
    .analytics-date-day { display:block; margin-top:3px; font-size:10px; font-weight:400; color:var(--muted-foreground); }
    .analytics-activity-bars { display:flex; height:6px; width:100%; min-width:70px; max-width:180px; border-radius:4px; overflow:hidden; background:var(--muted); }
    .analytics-received { background:var(--info); }
    .analytics-resolved { background:var(--success); }
    .analytics-closed { background:var(--warning); }
    .analytics-legend { display:flex; gap:14px; flex-wrap:wrap; }
    .analytics-legend span { display:inline-flex; align-items:center; gap:5px; font-size:10px; color:var(--muted-foreground); }
    .analytics-legend i { display:inline-block; width:7px; height:7px; border-radius:2px; }
    .analytics-table-footer { display:grid; gap:12px; margin-top:16px; padding-top:14px; border-top:1px solid var(--border); }
    .analytics-empty { display:grid; justify-items:center; padding:36px 16px; text-align:center; }
    .analytics-empty svg { width:32px; height:32px; color:var(--muted-foreground); margin-bottom:14px; }
    .analytics-breakdowns { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:20px; }
    .analytics-breakdown-list { display:grid; gap:16px; margin-top:20px; max-height:280px; overflow-y:auto; }
    .analytics-breakdown-label { display:flex; justify-content:space-between; gap:16px; font-size:12px; margin-bottom:7px; }
    .analytics-breakdown-track { height:5px; border-radius:4px; background:var(--muted); overflow:hidden; }
    .analytics-breakdown-track span { display:block; height:100%; border-radius:4px; background:var(--primary); }
    .analytics-subject { max-width:380px; overflow-wrap:anywhere; }
    .analytics-refresh-time { font-size:11px; color:var(--muted-foreground); }
    @media(max-width:1000px) { .analytics-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } .analytics-snapshot { grid-template-columns:repeat(3,minmax(0,1fr)); row-gap:10px; } }
    @media(max-width:640px) { .analytics-page { gap:14px; } .analytics-page .card-body,.analytics-kpi { padding:16px; } .analytics-breakdowns { grid-template-columns:1fr; } .analytics-kpi-value { font-size:26px; } .analytics-filter-fields { align-items:flex-end; } .analytics-filter-fields .form-input { width:145px; } .analytics-snapshot { grid-template-columns:repeat(2,minmax(0,1fr)); } .analytics-snapshot-item { padding:12px; } }
</style>
@endpush
@section('content')
@php
    $metrics = $analytics['metrics'];
    $isAdmin = $analytics['role'] === 'admin';
    $generatedAt = \Carbon\CarbonImmutable::parse($analytics['generated_at'])->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'));
    $today = \Carbon\CarbonImmutable::today('Asia/Dhaka');
@endphp
<div class="analytics-page">
    <header class="analytics-header"><div><h1 class="page-title">Ticket analytics</h1><p class="analytics-note">{{ $isAdmin ? 'Organization-wide performance and support workload.' : 'Performance and workload for your assigned tickets.' }}</p></div><div><a class="btn btn-secondary" href="{{ route('dashboard.tickets.index') }}">Open ticket worklist &rarr;</a><p class="analytics-refresh-time">Updated {{ $generatedAt->format('d M, h:i A') }} · {{ config('ticketing.display_timezone', 'Asia/Dhaka') }}</p></div></header>
    <section class="card"><div class="card-body analytics-filter-bar">
        <form method="GET" action="{{ route('dashboard.analytics.index') }}" class="analytics-filter-fields">
            <div><label class="form-label" for="from">From · Dhaka date</label><input id="from" name="from" type="date" class="form-input" value="{{ $analytics['filters']['from'] }}" required></div>
            <div><label class="form-label" for="to">To · Dhaka date</label><input id="to" name="to" type="date" class="form-input" value="{{ $analytics['filters']['to'] }}" required></div>
            <button class="btn btn-primary" type="submit">Apply range</button>
        </form>
        <nav class="analytics-presets" aria-label="Quick date ranges">@foreach([7,30,90] as $days)<a class="btn {{ $analytics['filters']['from'] === $today->subDays($days - 1)->toDateString() && $analytics['filters']['to'] === $today->toDateString() ? 'btn-secondary' : 'btn-ghost' }}" href="{{ route('dashboard.analytics.index', ['from'=>$today->subDays($days - 1)->toDateString(), 'to'=>$today->toDateString()]) }}">{{ $days }} days</a>@endforeach</nav>
    </div></section>
    <section class="analytics-kpis" aria-label="Selected-period performance">
        <article class="analytics-kpi"><span class="analytics-kpi-label">Tickets received</span><div class="analytics-kpi-value">{{ number_format($metrics['created']) }}</div><p class="analytics-note">{{ $metrics['created_change'] === null ? 'No previous-period baseline' : sprintf('%+.1f%% vs previous period', $metrics['created_change']) }}</p></article>
        <article class="analytics-kpi"><span class="analytics-kpi-label">Tickets resolved</span><div class="analytics-kpi-value">{{ number_format($metrics['resolved']) }}</div><p class="analytics-note">{{ $metrics['resolved_change'] === null ? 'No previous-period baseline' : sprintf('%+.1f%% vs previous period', $metrics['resolved_change']) }}</p></article>
        <article class="analytics-kpi"><span class="analytics-kpi-label">SLA compliance</span><div class="analytics-kpi-value">{{ $metrics['sla_compliance_percent'] === null ? '—' : number_format($metrics['sla_compliance_percent'], 1).'%' }}</div><p class="analytics-note">{{ number_format($metrics['sla_met_cycles']) }} met of {{ number_format($metrics['resolution_samples']) }} measured resolution cycles</p></article>
        <article class="analytics-kpi"><span class="analytics-kpi-label">Average resolution</span><div class="analytics-kpi-value">{{ $metrics['average_resolution_seconds'] === null ? '—' : number_format($metrics['average_resolution_seconds'] / 3600, 1).' h' }}</div><p class="analytics-note">Median {{ $metrics['median_resolution_seconds'] === null ? '—' : number_format($metrics['median_resolution_seconds'] / 3600, 1).' h' }} · business time</p></article>
    </section>
    <section class="card"><div class="card-body"><div class="analytics-section-heading"><div><h2>Current workload</h2><p class="analytics-note">Live backlog across all dates, independent of the reporting period.</p></div><span class="analytics-period-tag">Current snapshot</span></div>
        <div class="analytics-snapshot">
            <a class="analytics-snapshot-item" href="{{ route('dashboard.tickets.index') }}"><span>Active backlog</span><strong>{{ number_format($metrics['backlog']) }}</strong></a>
            @if($isAdmin)<div class="analytics-snapshot-item"><span>Unassigned</span><strong>{{ number_format($metrics['unassigned']) }}</strong></div><div class="analytics-snapshot-item"><span>Critical priority</span><strong>{{ number_format($metrics['critical']) }}</strong></div>@else<div class="analytics-snapshot-item"><span>Unassigned queue</span><strong>{{ number_format($metrics['queue_preview'] ?? 0) }}</strong></div>@endif
            <a class="analytics-snapshot-item" href="{{ route('dashboard.tickets.index', ['sla_state'=>'approaching']) }}"><span>Approaching SLA</span><strong style="color:var(--warning)">{{ number_format($metrics['approaching']) }}</strong></a>
            <a class="analytics-snapshot-item" href="{{ route('dashboard.tickets.index', ['sla_state'=>'breached']) }}"><span>Breached SLA</span><strong style="color:var(--destructive)">{{ number_format($metrics['breached']) }}</strong></a>
            <a class="analytics-snapshot-item" href="{{ route('dashboard.tickets.index', ['sla_state'=>'paused']) }}"><span>Waiting for customer</span><strong>{{ number_format($metrics['paused']) }}</strong></a>
        </div>
        <p class="analytics-note">{{ number_format($metrics['ever_breached']) }} visible tickets have a recorded historical breach. Disabled tickets remain included; archived tickets are excluded from this snapshot.</p>
    </div></section>
    <section id="daily-activity" class="card"><div class="card-body">
        <div class="analytics-section-heading"><div><h2>Daily activity</h2><p class="analytics-note">{{ $analytics['filters']['from'] }} – {{ $analytics['filters']['to'] }} · {{ number_format($dailyActivity->total()) }} days with activity · idle days hidden</p></div>
            @if($dailyActivity->total() > 0)
            <form method="GET" action="{{ route('dashboard.analytics.index') }}#daily-activity" class="analytics-table-controls">
                <input type="hidden" name="from" value="{{ $analytics['filters']['from'] }}"><input type="hidden" name="to" value="{{ $analytics['filters']['to'] }}">
                <label for="activity_per_page">Show <select id="activity_per_page" name="activity_per_page" class="form-input">@foreach([10,20,30] as $size)<option value="{{ $size }}" @selected($dailyActivity->perPage() === $size)>{{ $size }}</option>@endforeach</select> days</label>
                <select name="activity_order" class="form-input" aria-label="Activity date order"><option value="desc" @selected($activityOrder === 'desc')>Newest first</option><option value="asc" @selected($activityOrder === 'asc')>Oldest first</option></select>
                <button class="btn btn-secondary" type="submit">Apply</button>
            </form>
            @endif
        </div>
        @if($dailyActivity->total() > 0)
        <div class="analytics-daily-summary"><span><strong>{{ number_format($activityTotals['created']) }}</strong>received</span><span><strong>{{ number_format($activityTotals['resolved']) }}</strong>resolution events</span><span><strong>{{ number_format($activityTotals['closed']) }}</strong>closure events</span><span>Totals for the full selected period</span></div>
        <div class="table-responsive"><table class="table" aria-label="Days with ticket activity"><thead><tr><th scope="col">Date · Dhaka</th><th scope="col">Received</th><th scope="col">Resolution events</th><th scope="col">Closure events</th><th scope="col">Activity volume</th></tr></thead><tbody>
            @foreach($dailyActivity as $day)
            <tr><th scope="row">{{ \Carbon\CarbonImmutable::parse($day['date'])->format('d M Y') }}<span class="analytics-date-day">{{ \Carbon\CarbonImmutable::parse($day['date'])->format('l') }}</span></th>
                @foreach(['created','resolved','closed'] as $metric)<td class="{{ $day[$metric] > 0 ? 'analytics-number' : 'analytics-zero' }}">{{ $day[$metric] > 0 ? number_format($day[$metric]) : '—' }}</td>@endforeach
                <td><div class="analytics-activity-bars" aria-hidden="true">@foreach(['created'=>'received','resolved'=>'resolved','closed'=>'closed'] as $metric=>$color)<span class="analytics-{{ $color }}" style="width:{{ round($day[$metric] / $activityPeak * 100, 2) }}%"></span>@endforeach</div></td>
            </tr>
            @endforeach
        </tbody></table></div>
        <div class="analytics-table-footer"><div class="analytics-legend"><span><i class="analytics-received"></i>Received</span><span><i class="analytics-resolved"></i>Resolution events</span><span><i class="analytics-closed"></i>Closure events</span></div>
            <p class="analytics-note">A reopened ticket can have multiple resolution events. The resolved KPI above counts distinct tickets, while this table counts events.</p>
            @if($dailyActivity->hasPages()){{ $dailyActivity->onEachSide(1)->links() }}@else<p class="analytics-note">Showing {{ $dailyActivity->count() }} of {{ $dailyActivity->total() }} active days.</p>@endif
        </div>
        @else
        <div class="analytics-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 4v16h16M8 16v-4m4 4V8m4 8v-6"/></svg><h2>No activity in this date range</h2><p class="analytics-note">There were no ticket creations, resolutions, or closures. Choose a different reporting period.</p></div>
        @endif
    </div></section>
    @if($isAdmin)
    <div class="analytics-breakdowns">
        @foreach(['status_breakdown'=>'Backlog by status','agent_workload'=>'Workload by agent','priority_breakdown'=>'Backlog by priority','category_breakdown'=>'Backlog by category'] as $key=>$heading)
        <section class="card"><div class="card-body"><h2>{{ $heading }}</h2><p class="analytics-note">Current active tickets</p><div class="analytics-breakdown-list">
            @forelse($analytics[$key] as $row)
            @php($total = $row['total'] ?? $row['value'])
            <div><div class="analytics-breakdown-label"><span>{{ $row['name'] ?? $row['label'] }}</span><strong>{{ number_format($total) }}</strong></div><div class="analytics-breakdown-track"><span style="width:{{ min(100, round($total / max(1, $metrics['backlog']) * 100, 2)) }}%"></span></div></div>
            @empty<p class="analytics-note">{{ $key === 'agent_workload' ? 'No assigned active tickets.' : 'No active tickets.' }}</p>@endforelse
        </div></div></section>
        @endforeach
    </div>
    @endif
    <section class="card"><div class="card-body"><div class="analytics-section-heading"><div><h2>Recent visible tickets</h2><p class="analytics-note">Latest tickets across all dates</p></div><a class="btn btn-ghost" href="{{ route('dashboard.tickets.index', ['status'=>'all']) }}">View worklist &rarr;</a></div>
        <div class="table-responsive"><table class="table"><thead><tr><th scope="col">Ticket</th><th scope="col">Subject</th><th scope="col">Status</th><th scope="col">Created</th></tr></thead><tbody>@forelse($analytics['recent_tickets'] as $ticket)<tr><td><a href="{{ route('dashboard.tickets.show', $ticket) }}">{{ $ticket->number }}</a></td><td class="analytics-subject"><span title="{{ $ticket->subject }}">{{ str($ticket->subject)->limit(64) }}</span></td><td><span class="badge badge-{{ in_array($ticket->status->value, ['resolved','closed'], true) ? 'success' : 'secondary' }}">{{ str($ticket->status->value)->replace('_', ' ')->title() }}</span>@if(!$ticket->is_active) <span class="badge badge-warning">Disabled</span>@endif</td><td>{{ $ticket->created_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M Y, h:i A') }}</td></tr>@empty<tr><td colspan="4">No visible tickets.</td></tr>@endforelse</tbody></table></div>
    </div></section>
    <p class="analytics-note">Reporting dates use Asia/Dhaka. Period totals stay unchanged when paging daily activity. Organization metrics are cached for up to 60 seconds.</p>
</div>
@endsection
