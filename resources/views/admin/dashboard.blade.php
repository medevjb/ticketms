@extends('tyro-dashboard::layouts.admin')
@section('title', 'Support overview')
@section('breadcrumb')<span>Support overview</span>@endsection
@push('styles')
<style>
    .overview { display:grid; gap:24px; }
    .overview-header,.overview-heading { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
    .overview-filters { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; }
    .overview-filter-controls { display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; }
    .overview-filter-actions { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .overview-filters .form-input,.overview-filters .btn { min-height:40px; box-sizing:border-box; }
    .overview h2 { font-size:16px; font-weight:600; margin:0; }
    .overview-subtitle { color:var(--muted-foreground); font-size:13px; margin:6px 0 0; line-height:1.6; }
    .overview-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; }
    .overview .stat-card { display:block; text-decoration:none; color:inherit; }
    .overview .stat-value { margin:12px 0; font-variant-numeric:tabular-nums; }
    .overview-panels { display:grid; grid-template-columns:2fr 1fr; gap:24px; }
    .overview-breakdowns { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:24px; }
    .overview .card { min-width:0; margin:0; }
    .overview .card-body { padding:24px; }
    .overview-legend { display:flex; gap:16px; font-size:12px; color:var(--muted-foreground); }
    .overview-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:6px; }
    .overview-chart { display:flex; align-items:end; height:220px; gap:4px; border-bottom:1px solid var(--border); margin-top:28px; }
    .overview-chart-day { flex:1; height:100%; display:flex; align-items:end; justify-content:center; gap:2px; min-width:2px; }
    .overview-bar { width:42%; border-radius:3px 3px 0 0; }
    .overview-axis { display:flex; justify-content:space-between; font-size:11px; color:var(--muted-foreground); margin-top:10px; }
    .overview-alert { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:17px 0; border-bottom:1px solid var(--border); color:inherit; text-decoration:none; }
    .overview-alert:last-child { border:0; }
    .overview-alert strong { font-size:22px; font-variant-numeric:tabular-nums; }
    .overview-row { margin-top:20px; }
    .overview-row-label { display:flex; justify-content:space-between; gap:12px; font-size:13px; margin-bottom:8px; }
    .overview-track { height:6px; background:var(--muted); border-radius:6px; overflow:hidden; }
    .overview-track span { display:block; height:100%; background:var(--primary); border-radius:6px; }
    .overview .table th,.overview .table td { white-space:normal; overflow-wrap:anywhere; }
    .overview .form-input { max-width:180px; }
    @media(max-width:1100px) { .overview-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } .overview-breakdowns { grid-template-columns:1fr; } }
    @media(max-width:720px) { .overview-panels { grid-template-columns:1fr; } .overview .card-body { padding:18px; } .overview-kpis { gap:10px; } .overview .stat-value { font-size:28px; } }
</style>
@endpush
@section('content')
@php
    $metrics = $analytics['metrics'];
    $isAdmin = $analytics['role'] === 'admin';
    $daily = $analytics['daily'];
    $peak = max(1, collect($daily)->max(fn ($day) => max($day['created'], $day['resolved'])) ?? 0);
    $homeRoute = $dashboardRoute::name('index');
    $generatedAt = \Carbon\CarbonImmutable::parse($analytics['generated_at'])->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'));
@endphp
<div class="overview">
    <header class="overview-header">
        <div><h1 class="page-title">Support overview</h1><p class="overview-subtitle">{{ $isAdmin ? 'Your support operation, at a glance.' : 'Your assigned tickets and service performance.' }} Updated {{ $generatedAt->format('d M, h:i A') }} · {{ config('ticketing.display_timezone', 'Asia/Dhaka') }}</p></div>
        <a class="btn btn-primary" href="{{ route('dashboard.tickets.index') }}">Manage tickets &rarr;</a>
    </header>
    <form method="GET" action="{{ route($homeRoute) }}" class="overview-filters">
        <div class="overview-filter-controls">
            <div><label class="form-label" for="from">From</label><input class="form-input" id="from" name="from" type="date" value="{{ $analytics['filters']['from'] }}" required></div>
            <div><label class="form-label" for="to">To</label><input class="form-input" id="to" name="to" type="date" value="{{ $analytics['filters']['to'] }}" required></div>
            <div class="overview-filter-actions">
                <button class="btn btn-secondary" type="submit">Apply range</button>
                <a class="btn btn-ghost" href="{{ route($homeRoute) }}">Last 30 days</a>
            </div>
        </div>
        <a class="btn btn-ghost" href="{{ route('dashboard.analytics.index', $analytics['filters']) }}">Detailed analytics &rarr;</a>
    </form>

    <section class="overview-kpis" aria-label="Key performance indicators">
        <a class="stat-card" href="{{ route('dashboard.tickets.index', ['status' => 'active']) }}"><div class="stat-label">Active backlog</div><div class="stat-value">{{ number_format($metrics['backlog']) }}</div><p class="overview-subtitle">Current open, in-progress and waiting tickets</p></a>
        <article class="stat-card"><div class="stat-label">Tickets received</div><div class="stat-value">{{ number_format($metrics['created']) }}</div><p class="overview-subtitle">{{ $metrics['created_change'] === null ? 'No previous-period baseline' : sprintf('%+.1f%% vs previous period', $metrics['created_change']) }}</p></article>
        <article class="stat-card"><div class="stat-label">Tickets resolved</div><div class="stat-value">{{ number_format($metrics['resolved']) }}</div><p class="overview-subtitle">{{ $metrics['resolved_change'] === null ? 'No previous-period baseline' : sprintf('%+.1f%% vs previous period', $metrics['resolved_change']) }}</p></article>
        <article class="stat-card"><div class="stat-label">SLA compliance</div><div class="stat-value">{{ $metrics['sla_compliance_percent'] === null ? '—' : number_format($metrics['sla_compliance_percent'], 1).'%' }}</div><p class="overview-subtitle">{{ number_format($metrics['resolution_samples']) }} resolution snapshots in selected period</p></article>
    </section>
    <div class="overview-panels">
        <section class="card"><div class="card-body">
            <div class="overview-heading"><h2>Ticket volume</h2><div class="overview-legend"><span><i class="overview-dot" style="background:var(--primary)"></i>Received</span><span><i class="overview-dot" style="background:var(--success)"></i>Resolution events</span></div></div>
            <p class="overview-subtitle">{{ $analytics['filters']['from'] }} – {{ $analytics['filters']['to'] }} · Daily activity · Peak {{ number_format($peak) }}</p>
            <div class="overview-chart" role="img" aria-label="Daily tickets received and resolution events. Exact values are available in the daily data below.">
                @foreach($daily as $day)
                <div class="overview-chart-day" title="{{ $day['date'] }}: {{ $day['created'] }} received, {{ $day['resolved'] }} resolution events">
                    <span class="overview-bar" style="height:{{ round($day['created'] / $peak * 100, 2) }}%;background:var(--primary)"></span>
                    <span class="overview-bar" style="height:{{ round($day['resolved'] / $peak * 100, 2) }}%;background:var(--success)"></span>
                </div>
                @endforeach
            </div>
            <div class="overview-axis"><span>{{ $analytics['filters']['from'] }}</span><span>{{ $analytics['filters']['to'] }}</span></div>
            @if(collect($daily)->sum('created') + collect($daily)->sum('resolved') === 0)<p class="overview-subtitle">No ticket activity in this period.</p>@endif
            <details style="margin-top:16px"><summary>View daily data</summary><div class="table-responsive"><table class="table"><thead><tr><th scope="col">Dhaka date</th><th scope="col">Received</th><th scope="col">Resolution events</th></tr></thead><tbody>@foreach($daily as $day)<tr><th scope="row">{{ $day['date'] }}</th><td>{{ $day['created'] }}</td><td>{{ $day['resolved'] }}</td></tr>@endforeach</tbody></table></div></details>
        </div></section>
        <section class="card"><div class="card-body"><h2>Needs attention</h2><p class="overview-subtitle">Current backlog, across all dates</p>
            <a class="overview-alert" href="{{ route('dashboard.tickets.index', ['sla_state' => 'breached']) }}"><span>Breached SLA</span><strong style="color:var(--danger)">{{ number_format($metrics['breached']) }}</strong></a>
            <a class="overview-alert" href="{{ route('dashboard.tickets.index', ['sla_state' => 'approaching']) }}"><span>Approaching SLA</span><strong style="color:var(--warning)">{{ number_format($metrics['approaching']) }}</strong></a>
            <div class="overview-alert"><span>{{ $isAdmin ? 'Unassigned tickets' : 'Unassigned queue' }}</span><strong>{{ number_format($metrics[$isAdmin ? 'unassigned' : 'queue_preview']) }}</strong></div>
            <a class="overview-alert" href="{{ route('dashboard.tickets.index', ['sla_state' => 'paused']) }}"><span>Waiting for customer</span><strong>{{ number_format($metrics['paused']) }}</strong></a>
            <div class="overview-alert"><span>Average resolution<br><small class="overview-subtitle">Business hours · selected period</small></span><strong>{{ $metrics['average_resolution_seconds'] === null ? '—' : number_format($metrics['average_resolution_seconds'] / 3600, 1).' h' }}</strong></div>
        </div></section>
    </div>
    @if($isAdmin)
    <div class="overview-breakdowns">
        @foreach(['agent_workload' => 'Agent workload', 'priority_breakdown' => 'Backlog by priority', 'category_breakdown' => 'Backlog by category'] as $key => $heading)
        <section class="card"><div class="card-body"><h2>{{ $heading }}</h2><p class="overview-subtitle">Current active tickets</p>
            @forelse($analytics[$key] as $row)
            <div class="overview-row"><div class="overview-row-label"><span>{{ $row['name'] }}</span><strong>{{ number_format($row['total']) }}</strong></div><div class="overview-track"><span style="width:{{ min(100, round($row['total'] / max(1, $metrics['backlog']) * 100, 2)) }}%"></span></div></div>
            @empty<p class="overview-subtitle">{{ $key === 'agent_workload' ? 'No assigned active tickets.' : 'No active tickets.' }}</p>@endforelse
        </div></section>
        @endforeach
    </div>
    @endif
    <section class="card"><div class="card-body"><div class="overview-heading"><div><h2>Recent tickets</h2><p class="overview-subtitle">Latest visible tickets, across all dates</p></div><a class="btn btn-ghost" href="{{ route('dashboard.tickets.index', ['status' => 'all']) }}">View all tickets &rarr;</a></div>
        <div class="table-responsive"><table class="table"><thead><tr><th scope="col">Ticket</th><th scope="col">Subject</th><th scope="col">Status</th><th scope="col">Created ({{ config('ticketing.display_timezone', 'Asia/Dhaka') }})</th></tr></thead><tbody>
            @forelse($analytics['recent_tickets'] as $ticket)
            <tr><td><a href="{{ route('dashboard.tickets.show', $ticket) }}">{{ $ticket->number }}</a></td><td><span title="{{ $ticket->subject }}">{{ str($ticket->subject)->limit(64) }}</span></td><td><span class="badge badge-{{ match($ticket->status->value) { 'resolved', 'closed' => 'success', 'waiting_customer' => 'warning', default => 'primary' } }}">{{ str($ticket->status->value)->replace('_', ' ')->title() }}</span></td><td>{{ $ticket->created_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M Y, h:i A') }}</td></tr>
            @empty<tr><td colspan="4">No tickets yet. New support requests will appear here.</td></tr>@endforelse
        </tbody></table></div>
    </div></section>
    <p class="overview-subtitle">Operational counts include disabled tickets and exclude archived tickets. Period metrics use Dhaka dates; resolved totals count distinct tickets, while the chart counts resolution events. Organization analytics refresh within 60 seconds.</p>
</div>
@endsection
