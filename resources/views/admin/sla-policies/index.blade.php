@extends('tyro-dashboard::layouts.admin')

@section('title', 'SLA policy')
@section('breadcrumb')<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span><span>SLA policy</span>@endsection

@push('styles')
<style>
    .sla-policy-page{display:grid;gap:1rem;width:100%;max-width:none}.sla-policy-page form{width:100%}.sla-policy-heading h1{margin:0 0 .3rem}.sla-policy-heading p{margin:0}.sla-policy-layout{display:grid;grid-template-columns:minmax(0,1fr);gap:1rem;width:100%}.sla-policy-panel{width:100%;min-width:0;padding:1rem}.sla-policy-panel h2{margin:0 0 .25rem;color:var(--foreground);font-size:.94rem;font-weight:750}.sla-policy-help{margin:0 0 .85rem;color:var(--muted-foreground);font-size:.74rem;line-height:1.5}.sla-policy-general{display:grid;grid-template-columns:minmax(220px,1fr) minmax(220px,.7fr) minmax(260px,1.1fr);align-items:start;gap:1rem}.sla-policy-general .form-label{display:block;min-height:1.1rem;margin-bottom:.35rem}.sla-policy-general .sla-policy-help{margin:.35rem 0 0}.sla-priority-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.75rem}.sla-priority-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(105px,135px);align-items:center;gap:.7rem;min-width:0;padding:.8rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--card)}.sla-priority-label{display:grid;gap:.2rem;min-width:0}.sla-priority-label strong{overflow:hidden;color:var(--foreground);font-size:.8rem;text-overflow:ellipsis;white-space:nowrap}.sla-priority-label small{color:var(--muted-foreground);font-size:.66rem;line-height:1.4}.sla-priority-row .form-label{margin-bottom:.3rem;color:var(--muted-foreground);font-size:.66rem}.sla-policy-footnote{margin:.85rem 0 0;padding:.7rem .8rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--muted);color:var(--muted-foreground);font-size:.72rem;line-height:1.5}.sla-policy-save{position:sticky;bottom:.5rem;z-index:2;display:flex;justify-content:flex-end;padding:.7rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--card);box-shadow:var(--card-shadow)}
    @media(max-width:900px){.sla-policy-general{grid-template-columns:repeat(2,minmax(0,1fr))}.sla-policy-calendar-note{grid-column:1/-1}}
    @media(max-width:600px){.sla-policy-panel{padding:.8rem}.sla-policy-general{grid-template-columns:1fr}.sla-policy-calendar-note{grid-column:auto}.sla-priority-list{grid-template-columns:1fr}.sla-priority-row{grid-template-columns:minmax(0,1fr) minmax(105px,130px);gap:.5rem}}
</style>
@endpush

@section('content')
@php
    $targetsByPriority = $policy->targets->keyBy('priority_id');
@endphp
<div class="sla-policy-page">
    <header class="sla-policy-heading"><h1 class="page-title">SLA policy</h1><p class="page-description">Set the resolution target for each priority and when to show an approaching-deadline warning. Updates are saved directly and used for new tickets.</p></header>

    <form action="{{ route('dashboard.sla-policies.update') }}" method="POST">
        @csrf @method('PUT')
        <div class="sla-policy-layout">
            <section class="card sla-policy-panel" aria-labelledby="sla-policy-settings-heading">
                <h2 id="sla-policy-settings-heading">Policy settings</h2>
                <p class="sla-policy-help">Set the policy name and the point when a ticket should be marked as approaching its deadline.</p>
                <div class="sla-policy-general">
                    <div><label for="name" class="form-label">Policy name</label><input id="name" name="name" maxlength="100" required class="form-input @error('name') is-invalid @enderror" value="{{ old('name', $policy->name) }}"></div>
                    <div><label for="warning_percent" class="form-label">Warn when target is used (%)</label><input id="warning_percent" name="warning_percent" type="number" min="1" max="99" required class="form-input @error('warning_percent') is-invalid @enderror" value="{{ old('warning_percent', $policy->warning_percent) }}"><p class="sla-policy-help">For example, 80% warns after 80% of business time has passed.</p></div>
                    <div class="sla-policy-calendar-note"><span class="form-label">Business hours</span><p class="sla-policy-help" style="margin:0">Targets count against the <a href="{{ route('dashboard.calendars.index') }}">business calendar</a>. Nights, closed days, and holidays are excluded.</p></div>
                </div>
            </section>

            <section class="card sla-policy-panel" aria-labelledby="sla-priority-targets-heading">
                <h2 id="sla-priority-targets-heading">Resolution targets</h2>
                <p class="sla-policy-help">Enter the allowed business time for each priority. Each ticket keeps the target assigned when it was created.</p>
                <div class="sla-priority-list">
                    @foreach($priorities as $index => $priority)
                        @php
                            $savedTarget = $targetsByPriority->get($priority->id)?->target_seconds;
                            $minutes = $savedTarget !== null ? max(1, (int) ceil($savedTarget / 60)) : '';
                        @endphp
                        <div class="sla-priority-row">
                            <div class="sla-priority-label"><strong>{{ $priority->name }}</strong><small>{{ $priority->is_active ? 'New tickets can use this priority' : 'This priority is currently disabled' }}</small></div>
                            <div><label for="target-{{ $priority->id }}" class="form-label">Target (minutes)</label><input id="target-{{ $priority->id }}" type="number" name="targets[{{ $index }}][target_minutes]" min="1" max="525600" step="1" @required($priority->is_active) value="{{ old("targets.{$index}.target_minutes", $minutes) }}" class="form-input @error("targets.{$index}.target_minutes") is-invalid @enderror"><input type="hidden" name="targets[{{ $index }}][priority_id]" value="{{ $priority->id }}"></div>
                        </div>
                    @endforeach
                </div>
                <p class="sla-policy-footnote">Target changes are used by new tickets. The calendar controls when an SLA clock runs, and the warning percent controls when an approaching-deadline warning appears.</p>
            </section>
        </div>
        <div class="sla-policy-save"><button type="submit" class="btn btn-primary">Save SLA policy</button></div>
    </form>
</div>
@endsection
