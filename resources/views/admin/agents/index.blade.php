@extends('tyro-dashboard::layouts.admin')
@section('title', 'Agent management')
@section('breadcrumb')
    <a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a>
    <span class="breadcrumb-separator">/</span>
    <span>Agents</span>
@endsection
@push('styles')
<style>
    .agent-assignment-switch{display:inline-flex;align-items:center;gap:.55rem;border:0;padding:.3rem .2rem;background:transparent;color:var(--muted-foreground);font:inherit;font-size:.78rem;font-weight:700;cursor:pointer}
    .agent-assignment-switch-track{position:relative;width:2.4rem;height:1.35rem;flex:none;border:1px solid var(--input);border-radius:999px;background:var(--muted);transition:background .16s ease,border-color .16s ease}
    .agent-assignment-switch-track>span{position:absolute;top:.15rem;left:.15rem;width:.95rem;height:.95rem;border-radius:50%;background:var(--background);box-shadow:0 1px 3px #0002;transition:transform .16s ease}
    .agent-assignment-switch.is-enabled{color:var(--foreground)}
    .agent-assignment-switch.is-enabled .agent-assignment-switch-track{border-color:var(--primary);background:var(--primary)}
    .agent-assignment-switch.is-enabled .agent-assignment-switch-track>span{transform:translateX(1.05rem)}
    .agent-assignment-switch:focus-visible{outline:2px solid var(--ring);outline-offset:3px;border-radius:.35rem}
    .agent-management-table td { vertical-align:middle; }
    .agent-assignment-switch:disabled { cursor:not-allowed; opacity:.55; }
</style>
@endpush

@section('content')
<div class="page-header">
    <div class="page-header-row">
        <div>
            <h1 class="page-title">Agent management</h1>
            <p class="page-description">Manage agent accounts, invitations, availability, and ticket assignment.</p>
        </div>
        <div style="display:flex;align-items:center;gap:.625rem;flex-wrap:wrap">
            <a class="btn btn-primary" href="{{ route('dashboard.agents.create') }}">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:1rem;height:1rem;vertical-align:middle;margin-right:.35rem"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14" /></svg>
                Create agent
            </a>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:1rem">
    <div class="card-body">
        <form method="GET" action="{{ route('dashboard.agents.index') }}">
            <div class="filters-bar" style="gap:.625rem">
                <label class="search-box" for="agent-search" style="flex:1;min-width:240px;max-width:520px;margin:0">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-4-4"/></svg>
                    <input class="form-input" type="search" id="agent-search" name="search" value="{{ $search }}" maxlength="150" placeholder="Search agents by name or email" aria-label="Search agents by name or email">
                </label>
                <button type="submit" class="btn btn-secondary">Search agents</button>
                @if($search !== '')
                    <a class="btn btn-ghost" href="{{ route('dashboard.agents.index') }}">Clear search</a>
                @endif
                <span class="page-description" style="margin:0 0 0 auto;white-space:nowrap">{{ number_format($agents->total()) }} {{ \Illuminate\Support\Str::plural('agent', $agents->total()) }}</span>
            </div>
        </form>
    </div>
</div>

<div class="card" style="margin-bottom:1rem">
    <div class="card-body"><h2 class="section-title">Automatic assignment</h2><p class="page-description" style="margin-bottom:0">Enable agents below to receive new tickets automatically. The eligible agent with the smallest active workload is selected first; ties favor the longest time since their last automatic assignment. Existing ticket assignments stay in place when this switch is turned off.</p></div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table agent-management-table">
            <thead><tr><th>Agent</th><th>Account and profile</th><th>Automatic assignment</th><th>Active workload</th><th>Last automatic assignment</th><th style="text-align:right">Actions</th></tr></thead>
            <tbody>
            @forelse($agents as $agent)
                @php
                    $profile = $agent->agentProfile;
                    $pendingInvitation = $profile !== null && $profile->invitation_token_hash !== null && $profile->invitation_accepted_at === null;
                    $isAvailable = ! $agent->trashed() && $agent->is_active && $profile !== null && ! $profile->trashed() && $profile->is_active;
                @endphp
                <tr>
                    <td><strong><span title="{{ $agent->name }}">{{ str($agent->name)->limit(36) }}</span></strong><br><span class="page-description"><span title="{{ $agent->email }}">{{ str($agent->email)->limit(40) }}</span></span></td>
                    <td>
                        @if($agent->trashed())
                            <span class="badge badge-secondary">Archived</span>
                        @elseif($pendingInvitation)
                            <span class="badge badge-warning">Invitation pending</span>
                            <br><span class="page-description">Expires {{ $profile->invitation_expires_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M Y, h:i A') ?? 'unknown' }} {{ config('ticketing.display_timezone', 'Asia/Dhaka') }} time</span>
                        @elseif($profile === null)
                            <span class="badge badge-warning">Profile missing</span>
                        @elseif($profile->trashed())
                            <span class="badge badge-secondary">Profile archived</span>
                        @elseif(! $agent->is_active || ! $profile->is_active)
                            <span class="badge badge-secondary">Disabled</span>
                        @else
                            <span class="badge badge-success">Active</span>
                        @endif
                    </td>
                    <td>
                        @if($isAvailable)
                            <form method="POST" action="{{ route('dashboard.ticket-agents.auto-assignment', $agent->id) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <input type="hidden" name="auto_assignment_enabled" value="{{ $profile->auto_assignment_enabled ? '0' : '1' }}">
                                <button class="agent-assignment-switch {{ $profile->auto_assignment_enabled ? 'is-enabled' : '' }}" type="submit" role="switch" aria-checked="{{ $profile->auto_assignment_enabled ? 'true' : 'false' }}" aria-label="Automatic assignment for {{ $agent->name }}">
                                    <span class="agent-assignment-switch-track" aria-hidden="true"><span></span></span>
                                    <span class="agent-assignment-switch-label">{{ $profile->auto_assignment_enabled ? 'Enabled' : 'Disabled' }}</span>
                                </button>
                            </form>
                        @else
                            <span class="page-description">Unavailable</span><br><small class="page-description">Requires an active account and profile.</small>
                        @endif
                    </td>
                    <td><strong>{{ number_format((int) $agent->active_workload) }}</strong></td>
                    <td>{{ $profile?->last_auto_assigned_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M Y, h:i A') ?? 'Never' }}</td>
                    <td style="text-align:right">
                        <div style="display:flex;justify-content:flex-end;gap:.4rem;flex-wrap:wrap">
                            @if(! $agent->trashed() && $profile === null)
                                <form method="POST" action="{{ route('dashboard.agents.profile.create', $agent->id) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-secondary" type="submit">Create profile</button>
                                </form>
                            @endif
                            @if(! $agent->trashed() && $pendingInvitation)
                                <form method="POST" action="{{ route('dashboard.agents.invitation.resend', $agent->id) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-secondary" type="submit">Resend invitation</button>
                                </form>
                            @elseif(! $agent->trashed() && $profile !== null && ! $profile->trashed() && ! $pendingInvitation)
                                <form method="POST" action="{{ route('dashboard.agents.availability', $agent->id) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $agent->is_active ? '0' : '1' }}">
                                    <button class="btn btn-sm {{ $agent->is_active ? 'btn-ghost' : 'btn-secondary' }}" type="submit">
                                        {{ $agent->is_active ? 'Disable' : 'Enable' }} account
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty-state">No agent accounts found. Create an agent to send a password setup invitation.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($agents->hasPages())<div class="card-body">{{ $agents->links() }}</div>@endif
</div>
@endsection
