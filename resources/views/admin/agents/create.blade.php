@extends('tyro-dashboard::layouts.admin')
@section('title', 'Create agent')
@section('breadcrumb')
    <a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a>
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('dashboard.agents.index') }}">Agents</a>
    <span class="breadcrumb-separator">/</span>
    <span>Create</span>
@endsection
@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Create agent</h1>
        <p class="page-description">The agent will receive a one-time email link to set a password and activate their account.</p>
    </div>
</div>

<div class="card" style="max-width:720px">
    <div class="card-body">
        <form method="POST" action="{{ route('dashboard.agents.store') }}" class="form-stack">
            @csrf
            <div class="form-group">
                <label class="form-label" for="name">Name</label>
                <input class="form-input" id="name" name="name" value="{{ old('name') }}" maxlength="255" required autocomplete="name">
                @error('name')<p class="form-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="form-group">
                <label class="form-label" for="email">Email</label>
                <input class="form-input" id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" required autocomplete="email">
                <p class="page-description">The invitation link is sent to this address. It expires after {{ (int) config('ticketing.agent_invitation_expire_minutes', 1440) }} minutes.</p>
                @error('email')<p class="form-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="form-group">
                <input type="hidden" name="auto_assignment_enabled" value="0">
                <label class="form-check-label" for="auto_assignment_enabled">
                    <input id="auto_assignment_enabled" name="auto_assignment_enabled" type="checkbox" value="1" @checked(old('auto_assignment_enabled', '1') === '1')>
                    Include this agent in automatic ticket assignment after they accept the invitation
                </label>
                @error('auto_assignment_enabled')<p class="form-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">Create agent and send invitation</button>
                <a href="{{ route('dashboard.agents.index') }}" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
