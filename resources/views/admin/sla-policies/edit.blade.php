@extends('tyro-dashboard::layouts.admin')
@section('title','Edit SLA policy draft')
@section('breadcrumb')<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span><a href="{{ route('dashboard.sla-policies.index') }}">SLA policies</a><span class="breadcrumb-separator">/</span><span>Edit draft</span>@endsection
@section('content')
<div class="page-header"><div class="page-header-row"><div><h1 class="page-title">Edit SLA policy draft · v{{ $policy->version_no }}</h1><p class="page-description">Targets are stored as business seconds. This policy affects new tickets only after it is published and selected.</p></div></div></div>
<div class="card"><div class="card-body"><form action="{{ route('dashboard.sla-policies.update',$policy) }}" method="POST">@csrf @method('PUT')
<div style="max-width:900px;display:grid;gap:1.25rem">
<div><label for="name" class="form-label">Policy name <span aria-hidden="true">*</span></label><input id="name" name="name" maxlength="100" required class="form-input @error('name') is-invalid @enderror" value="{{ old('name',$policy->name) }}">@error('name')<p class="form-error" role="alert">{{ $message }}</p>@enderror</div>
<div><label for="calendar_version_id" class="form-label">Business calendar <span aria-hidden="true">*</span></label><select id="calendar_version_id" name="calendar_version_id" class="form-select" required>@foreach($calendars as $calendar)<option value="{{ $calendar->id }}" @selected((int)old('calendar_version_id',$policy->calendar_version_id)===$calendar->id)>v{{ $calendar->version_no }} · {{ $calendar->name }}{{ $calendar->is_active ? '' : ' (disabled)' }}</option>@endforeach</select>@error('calendar_version_id')<p class="form-error" role="alert">{{ $message }}</p>@enderror</div>
<div><label for="warning_percent" class="form-label">Approaching warning threshold (%) <span aria-hidden="true">*</span></label><input id="warning_percent" type="number" name="warning_percent" min="1" max="99" required class="form-input @error('warning_percent') is-invalid @enderror" value="{{ old('warning_percent',$policy->warning_percent) }}"><small class="page-description">Default is 80%. The breach threshold is always 100%.</small>@error('warning_percent')<p class="form-error" role="alert">{{ $message }}</p>@enderror</div>
<fieldset><legend class="form-label">Resolution targets by priority</legend><p class="page-description">Every enabled priority must have a target before publication. One minute minimum; values are entered in minutes and saved as seconds.</p>
@php($targetsByPriority = $policy->targets->keyBy('priority_id'))
@foreach($priorities as $index=>$priority)
    @php($existingTarget = $targetsByPriority->get($priority->id))
    @php($targetMinutes = $existingTarget ? intdiv((int)$existingTarget->target_seconds,60) : '')
    <div style="display:grid;grid-template-columns:minmax(180px,1fr) minmax(180px,260px);gap:.75rem;align-items:end;padding:.75rem 0;border-bottom:1px solid var(--border)">
        <div><label for="target-{{ $priority->id }}" class="form-label">{{ $priority->name }}@if($priority->trashed()) <span class="badge badge-secondary">Archived</span>@endif</label><input type="hidden" name="targets[{{ $index }}][priority_id]" value="{{ $priority->id }}"><small class="page-description">{{ $priority->slug }}</small></div>
        <div><label for="target-{{ $priority->id }}" class="form-label">Target (minutes)</label><input id="target-{{ $priority->id }}" type="number" name="targets[{{ $index }}][target_minutes]" min="1" max="525600" step="1" @required($priority->is_active || $priority->trashed()) value="{{ old("targets.{$index}.target_minutes",$targetMinutes) }}" class="form-input @error("targets.{$index}.target_minutes") is-invalid @enderror">@error("targets.{$index}.target_minutes")<p class="form-error" role="alert">{{ $message }}</p>@enderror</div>
    </div>
@endforeach
@error('targets')<p class="form-error" role="alert">{{ $message }}</p>@enderror
</fieldset></div>
<div style="display:flex;gap:.5rem;margin-top:1.25rem"><button type="submit" class="btn btn-primary">Save draft</button><a href="{{ route('dashboard.sla-policies.index') }}" class="btn btn-ghost">Cancel</a></div></form></div></div>
@endsection
