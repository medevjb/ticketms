@extends('tyro-dashboard::layouts.admin')
@section('title', 'Ticket assignment')
@section('breadcrumb')<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span><a href="{{ route('dashboard.tickets.index') }}">Tickets</a><span class="breadcrumb-separator">/</span><span>Agent assignment</span>@endsection
@section('content')
    <div class="page-header"><div><h1 class="page-title">Ticket assignment</h1><p class="page-description">Automatic assignment chooses the eligible agent with the smallest active workload, then the longest time since their last automatic assignment.</p></div><a class="btn btn-secondary" href="{{ route('dashboard.agents.index') }}">Manage agents</a></div>

<div class="card"><div class="card-body"><div class="table-responsive"><table class="table"><thead><tr><th>Agent</th><th>Profile</th><th>Automatic assignment</th><th>Active workload</th></tr></thead><tbody>
@forelse($agents as $agentProfile)
<tr>
    <td><strong>{{ $agentProfile->user?->name }}</strong><br><span class="page-description">{{ $agentProfile->user?->email }}</span></td>
    <td><span class="badge badge-success">Active</span></td>
    <td>
        <form method="POST" action="{{ route('dashboard.ticket-agents.auto-assignment', $agentProfile->user_id) }}" data-axios-form="agent-eligibility">
            @csrf @method('PATCH')
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="auto_assignment_enabled" value="{{ $agentProfile->auto_assignment_enabled ? '0' : '1' }}">
            <button class="agent-assignment-switch {{ $agentProfile->auto_assignment_enabled ? 'is-enabled' : '' }}" type="submit" role="switch" aria-checked="{{ $agentProfile->auto_assignment_enabled ? 'true' : 'false' }}" aria-label="Automatic assignment for {{ $agentProfile->user?->name }}">
                <span class="agent-assignment-switch-track" aria-hidden="true"><span></span></span>
                <span class="agent-assignment-switch-label">{{ $agentProfile->auto_assignment_enabled ? 'Enabled' : 'Disabled' }}</span>
            </button>
        </form>
    </td>
    <td><strong>{{ number_format((int) ($agentProfile->user?->live_assignment_count ?? 0)) }}</strong></td>
</tr>
@empty
<tr><td colspan="4">No agents with active profiles are available.</td></tr>
@endforelse
</tbody></table></div></div></div>
<div style="margin-top:1rem"><a class="btn btn-ghost" href="{{ route('dashboard.tickets.index') }}">Back to tickets</a></div>
@push('styles')
<style>
    .agent-assignment-switch{display:inline-flex;align-items:center;gap:.55rem;border:0;padding:.3rem .2rem;background:transparent;color:var(--muted-foreground);font:inherit;font-size:.78rem;font-weight:700;cursor:pointer}
    .agent-assignment-switch-track{position:relative;width:2.4rem;height:1.35rem;flex:none;border:1px solid var(--input);border-radius:999px;background:var(--muted);transition:background .16s ease,border-color .16s ease}
    .agent-assignment-switch-track>span{position:absolute;top:.15rem;left:.15rem;width:.95rem;height:.95rem;border-radius:50%;background:var(--background);box-shadow:0 1px 3px #0002;transition:transform .16s ease}
    .agent-assignment-switch.is-enabled{color:var(--foreground)}
    .agent-assignment-switch.is-enabled .agent-assignment-switch-track{border-color:var(--primary);background:var(--primary)}
    .agent-assignment-switch.is-enabled .agent-assignment-switch-track>span{transform:translateX(1.05rem)}
    .agent-assignment-switch:focus-visible{outline:2px solid var(--ring);outline-offset:3px;border-radius:.35rem}
</style>
@endpush
@push('scripts')
@vite('resources/js/ticket-operations.ts')
@endpush
@endsection
