@extends('tyro-dashboard::layouts.admin')
@section('title', 'Create ticket')
@section('breadcrumb')<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span><a href="{{ route('dashboard.tickets.index') }}">Tickets</a><span class="breadcrumb-separator">/</span><span>Create</span>@endsection
@section('content')
<div class="page-header"><div><h1 class="page-title">Create a support ticket</h1><p class="page-description">Share a few details and we’ll get your request to the right place.</p></div></div>

<div class="ticket-create-shell">
    <div class="ticket-create-intro">
        <div><span class="ticket-create-eyebrow">A clear path to support</span><h2>Let’s get this sorted.</h2><p>Complete each step. You can review your request before it is sent.</p></div>
        <div class="ticket-create-time"><span aria-hidden="true">◷</span><span><strong>Business schedule</strong><br>@if($slaOverview) Configured service hours · {{ $slaOverview['timezone'] }} @else Current schedule shown when you create your ticket @endif</span></div>
    </div>

    <div class="card ticket-create-card">
        <div class="ticket-stepper" aria-label="Ticket creation progress">
            <div class="ticket-step-indicator is-current" data-step-indicator="0"><span>1</span><div><strong>Request details</strong><small>What do you need?</small></div></div>
            <span class="ticket-step-connector" aria-hidden="true"></span>
            <div class="ticket-step-indicator" data-step-indicator="1"><span>2</span><div><strong>Priority & files</strong><small>Help us understand impact</small></div></div>
            <span class="ticket-step-connector" aria-hidden="true"></span>
            <div class="ticket-step-indicator" data-step-indicator="2"><span>3</span><div><strong>Review</strong><small>Check before sending</small></div></div>
        </div>

        <div class="card-body">
            @if(in_array('admin', auth()->user()->tyroRoleSlugs(), true))
                <form method="GET" action="{{ route('dashboard.tickets.create') }}" class="ticket-customer-search">
                    <label class="form-label" for="customer_search">Find a verified customer</label>
                    <div class="ticket-customer-search-controls"><input id="customer_search" name="customer_search" type="search" maxlength="150" class="form-input" value="{{ $customerSearch }}" placeholder="Search by name or email"><button class="btn btn-secondary" type="submit">Search</button></div>
                    <p class="ticket-field-hint">Search to narrow the list. Up to 50 matching customers are shown.</p>
                </form>
            @endif
            <form id="ticket-create-form" method="POST" enctype="multipart/form-data" action="{{ route('dashboard.tickets.store') }}">
                @csrf
                <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">



                <section class="ticket-form-step" data-form-step="0" data-has-errors="{{ $errors->has('customer_id') || $errors->has('subject') || $errors->has('description') || $errors->has('category_id') ? 'true' : 'false' }}" aria-labelledby="ticket-step-one-title">
                    <div class="ticket-form-heading"><span>STEP 1 OF 3</span><h2 id="ticket-step-one-title">Tell us about your request</h2><p>A clear subject and a little context help us understand where to start.</p></div>
                    <div class="ticket-form-fields">
                        @if(in_array('admin', auth()->user()->tyroRoleSlugs(), true))<div><label class="form-label" for="customer_id">Customer <span aria-hidden="true">*</span></label><select id="customer_id" name="customer_id" required class="form-input @error('customer_id') is-invalid @enderror"><option value="">Select a verified customer</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>{{ $customer->name }} · {{ $customer->email }}</option>@endforeach</select></div>@endif
                        <div><label class="form-label" for="subject">Subject <span aria-hidden="true">*</span></label><input id="subject" name="subject" maxlength="255" required class="form-input @error('subject') is-invalid @enderror" value="{{ old('subject') }}" placeholder="A short summary of the issue"></div>
                        <div><label class="form-label" for="description">Description <span aria-hidden="true">*</span></label><textarea id="description" name="description" rows="7" maxlength="20000" required class="form-input @error('description') is-invalid @enderror" placeholder="What were you trying to do? What happened?">{{ old('description') }}</textarea><p class="ticket-field-hint">Include any steps that help us understand or reproduce the issue.</p></div>
                        <div><label class="form-label" for="category_id">Category <span aria-hidden="true">*</span></label><select id="category_id" name="category_id" required class="form-input @error('category_id') is-invalid @enderror"><option value="">Choose the closest match</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></div>
                    </div>
                </section>

                <section class="ticket-form-step" data-form-step="1" data-has-errors="{{ $errors->has('priority_id') || collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'attachments')) || $errors->has('sla') ? 'true' : 'false' }}" aria-labelledby="ticket-step-two-title">
                    <div class="ticket-form-heading"><span>STEP 2 OF 3</span><h2 id="ticket-step-two-title">Set priority and add files</h2><p>Choose the impact that best describes the request. Add relevant files if they will help.</p></div>
                    <div class="ticket-form-fields">
                        <div><label class="form-label" for="priority_id">Priority <span aria-hidden="true">*</span></label><select id="priority_id" name="priority_id" required class="form-input @error('priority_id') is-invalid @enderror"><option value="">Choose a priority</option>@foreach($priorities as $priority)<option value="{{ $priority->id }}" @selected((string) old('priority_id') === (string) $priority->id)>{{ $priority->name }}</option>@endforeach</select><p class="ticket-field-hint">Select the level that most closely reflects the impact of this issue.</p></div>
                        <div><label class="form-label" for="attachments">Attachments <span class="ticket-optional">Optional</span></label><label class="ticket-upload-box" for="attachments"><span class="ticket-upload-icon" aria-hidden="true">↑</span><span><strong>Choose files to attach</strong><small id="ticket-file-names">Screenshots or documents can provide useful context.</small></span><input id="attachments" name="attachments[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.txt,.csv" class="form-input @error('attachments') is-invalid @enderror"></label><p class="ticket-field-hint">Up to 5 private files, 10 MiB each: PDF, JPEG, PNG, TXT or CSV.</p> </div>

                        <div class="ticket-sla-note"><span aria-hidden="true">◷</span><p><strong>How the resolution target works</strong><br>@if($slaOverview) Targets count scheduled business hours in {{ $slaOverview['timezone'] }}, excluding configured holidays. Service windows: {{ collect($slaOverview['windows'])->map(fn ($window) => $window['day'].' '.$window['hours'])->join(', ') }}. @else Targets count scheduled business hours and exclude configured holidays. @endif The clock pauses while a ticket is waiting for your reply and resumes when you respond.</p></div>
                    </div>
                </section>

                <section class="ticket-form-step" data-form-step="2" data-has-errors="false" aria-labelledby="ticket-step-three-title">
                    <div class="ticket-form-heading"><span>STEP 3 OF 3</span><h2 id="ticket-step-three-title">Review your request</h2><p>Make sure the details look right. You can go back to change anything.</p></div>
                    <dl class="ticket-review-list">
                        <div><dt>Subject</dt><dd data-review="subject">Not provided</dd></div>
                        <div><dt>Category</dt><dd data-review="category_id">Not selected</dd></div>
                        <div><dt>Priority</dt><dd data-review="priority_id">Not selected</dd></div>
                        <div><dt>Description</dt><dd data-review="description">Not provided</dd></div>
                        <div><dt>Attachments</dt><dd data-review="attachments">No files added</dd></div>
                    </dl>
                    <div class="ticket-sla-note"><span aria-hidden="true">✓</span><p><strong>Everything submitted here stays in your support conversation.</strong><br>You’ll be able to follow updates from your dashboard.</p></div>
                </section>

                <div class="ticket-form-actions">
                    <a class="btn btn-ghost" href="{{ route('dashboard.tickets.index') }}">Cancel</a>
                    <div class="ticket-form-action-group"><button class="btn btn-ghost" type="button" data-step-back hidden>Back</button><button class="btn btn-primary" type="button" data-step-next hidden>Continue</button><button class="btn btn-primary" type="submit" data-step-submit>Submit ticket</button></div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
.ticket-create-shell{max-width:980px;margin:0 auto 2rem}.ticket-customer-search{max-width:760px;margin:0 0 1.5rem;padding:1rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--muted)}.ticket-customer-search-controls{display:flex;gap:.5rem}.ticket-customer-search-controls .form-input{min-width:0;flex:1}.ticket-create-intro{display:flex;align-items:flex-end;justify-content:space-between;gap:1.5rem;margin:0 0 1.25rem;padding:1.75rem 2rem;border-radius:1.25rem;background:linear-gradient(125deg,#0f172a,#164e63);color:#fff}.ticket-create-eyebrow{font-size:.72rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#a5f3fc}.ticket-create-intro h2{margin:.45rem 0;font-size:1.7rem;font-weight:750;letter-spacing:-.03em}.ticket-create-intro p{margin:0;color:#cbd5e1}.ticket-create-time{display:flex;align-items:center;gap:.75rem;max-width:270px;padding:.8rem 1rem;border:1px solid #ffffff25;border-radius:1rem;background:#ffffff0d;color:#dbeafe;font-size:.78rem;line-height:1.5}.ticket-create-time>span:first-child{font-size:1.5rem;color:#a5f3fc}.ticket-create-time strong{color:#fff}.ticket-create-card{overflow:hidden}.ticket-stepper{display:flex;align-items:center;padding:1.25rem 1.5rem;background:#f8fafc;border-bottom:1px solid #e2e8f0}.ticket-step-indicator{display:flex;align-items:center;gap:.7rem;color:#64748b;min-width:0}.ticket-step-indicator>span{display:grid;place-items:center;flex:0 0 2rem;height:2rem;border:1px solid #cbd5e1;border-radius:50%;background:#fff;font-size:.8rem;font-weight:750}.ticket-step-indicator strong,.ticket-step-indicator small{display:block;white-space:nowrap}.ticket-step-indicator strong{font-size:.78rem}.ticket-step-indicator small{margin-top:.15rem;font-size:.68rem;color:#94a3b8}.ticket-step-indicator.is-current{color:#0f172a}.ticket-step-indicator.is-current>span,.ticket-step-indicator.is-complete>span{border-color:#0e7490;background:#0e7490;color:#fff}.ticket-step-indicator.is-complete>span{font-size:0}.ticket-step-indicator.is-complete>span:after{content:'✓';font-size:.85rem}.ticket-step-connector{height:1px;flex:1;min-width:1rem;margin:0 .8rem;background:#cbd5e1}.ticket-form-step{padding:1.75rem 0}.ticket-form-step+.ticket-form-step{border-top:1px solid #e2e8f0}.ticket-form-heading{margin-bottom:1.5rem}.ticket-form-heading>span{font-size:.7rem;font-weight:800;letter-spacing:.14em;color:#0e7490}.ticket-form-heading h2{margin:.5rem 0 .35rem;font-size:1.45rem;font-weight:750;letter-spacing:-.025em;color:#0f172a}.ticket-form-heading p{margin:0;color:#64748b;font-size:.9rem}.ticket-form-fields{display:grid;gap:1.15rem;max-width:760px}.ticket-field-hint{margin:.4rem 0 0;color:#64748b;font-size:.78rem}.ticket-optional{margin-left:.4rem;color:#64748b;font-weight:400}.ticket-upload-box{display:flex;align-items:center;gap:1rem;padding:1.1rem;border:1px dashed #94a3b8;border-radius:.8rem;background:#f8fafc;cursor:pointer}.ticket-upload-box:hover{border-color:#0e7490;background:#ecfeff}.ticket-upload-icon{display:grid;place-items:center;width:2.7rem;height:2.7rem;border-radius:.75rem;background:#cffafe;color:#0e7490;font-size:1.4rem}.ticket-upload-box strong,.ticket-upload-box small{display:block}.ticket-upload-box strong{color:#0f172a;font-size:.87rem}.ticket-upload-box small{margin-top:.2rem;color:#64748b;font-size:.76rem}.ticket-upload-box input{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}.ticket-upload-box:focus-within{outline:2px solid #0891b2;outline-offset:2px}.ticket-sla-note{display:flex;gap:.8rem;padding:1rem;border:1px solid #bae6fd;border-radius:.8rem;background:#f0f9ff;color:#075985}.ticket-sla-note>span{font-size:1.2rem}.ticket-sla-note p{margin:0;font-size:.82rem;line-height:1.55}.ticket-sla-note strong{color:#0c4a6e}.ticket-review-list{margin:0;border:1px solid #e2e8f0;border-radius:.9rem;overflow:hidden}.ticket-review-list>div{display:grid;grid-template-columns:150px 1fr;gap:1rem;padding:.95rem 1rem;border-bottom:1px solid #e2e8f0}.ticket-review-list>div:last-child{border-bottom:0}.ticket-review-list dt{color:#64748b;font-size:.82rem;font-weight:650}.ticket-review-list dd{margin:0;color:#0f172a;font-size:.87rem;white-space:pre-wrap;overflow-wrap:anywhere}.ticket-form-errors{margin-bottom:1.25rem;padding:1rem 1.1rem;border:1px solid #fecaca;border-radius:.8rem;background:#fef2f2;color:#991b1b}.ticket-form-errors p{margin:.25rem 0 0;font-size:.85rem}.ticket-form-actions{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-top:.75rem;padding-top:1.25rem;border-top:1px solid #e2e8f0}.ticket-form-action-group{display:flex;gap:.65rem}.ticket-form-actions [hidden],.ticket-form-step[hidden]{display:none!important}
@media(max-width:640px){.ticket-create-intro{align-items:flex-start;flex-direction:column;padding:1.35rem}.ticket-create-time{max-width:none}.ticket-stepper{padding:.9rem .75rem}.ticket-step-indicator{gap:.35rem}.ticket-step-indicator>span{flex-basis:1.75rem;height:1.75rem}.ticket-step-indicator strong{font-size:.67rem}.ticket-step-indicator small{display:none}.ticket-step-connector{min-width:.3rem;margin:0 .35rem}.ticket-form-step{padding:1.35rem 0}.ticket-form-heading h2{font-size:1.25rem}.ticket-review-list>div{grid-template-columns:95px 1fr;gap:.6rem;padding:.8rem}.ticket-form-actions{align-items:stretch}.ticket-form-actions>.btn,.ticket-form-action-group>.btn{flex:1;justify-content:center}.ticket-form-action-group{flex:1}}
@media(prefers-reduced-motion:reduce){.ticket-upload-box,.ticket-create-intro,.ticket-step-indicator{scroll-behavior:auto}}
</style>
@endpush

@push('scripts')
<script>
(() => {
    const form = document.getElementById('ticket-create-form');
    if (!form) return;

    const steps = [...form.querySelectorAll('[data-form-step]')];
    const indicators = [...document.querySelectorAll('[data-step-indicator]')];
    const back = form.querySelector('[data-step-back]');
    const next = form.querySelector('[data-step-next]');
    const submit = form.querySelector('[data-step-submit]');
    let current = steps.findIndex((step) => step.dataset.hasErrors === 'true');
    if (current < 0) current = 0;
    form.noValidate = true;

    const showStep = (index) => {
        current = Math.max(0, Math.min(index, steps.length - 1));
        steps.forEach((step, position) => { step.hidden = position !== current; });
        indicators.forEach((indicator, position) => {
            indicator.classList.toggle('is-current', position === current);
            indicator.classList.toggle('is-complete', position < current);
            if (position === current) indicator.setAttribute('aria-current', 'step');
            else indicator.removeAttribute('aria-current');
        });
        back.hidden = current === 0;
        next.hidden = current === steps.length - 1;
        submit.hidden = current !== steps.length - 1;
    };

    const validateStep = (index) => {
        const invalid = [...steps[index].querySelectorAll('input,select,textarea')].find((field) => !field.checkValidity());
        if (invalid) {
            invalid.reportValidity();
            invalid.focus();
            return false;
        }
        return true;
    };

    const updateReview = () => {
        ['subject', 'description'].forEach((name) => {
            const field = form.elements.namedItem(name);
            const target = form.querySelector(`[data-review="${name}"]`);
            if (field && target) target.textContent = field.value.trim() || 'Not provided';
        });
        ['category_id', 'priority_id'].forEach((name) => {
            const field = form.elements.namedItem(name);
            const target = form.querySelector(`[data-review="${name}"]`);
            if (field && target) target.textContent = field.selectedOptions[0]?.textContent || 'Not selected';
        });
        const files = form.querySelector('#attachments')?.files;
        const fileTarget = form.querySelector('[data-review="attachments"]');
        if (fileTarget) fileTarget.textContent = files?.length ? [...files].map((file) => file.name).join(', ') : 'No files added';
        const fileHint = form.querySelector('#ticket-file-names');
        if (fileHint && files?.length) fileHint.textContent = [...files].map((file) => file.name).join(', ');
    };

    next.addEventListener('click', () => {
        if (validateStep(current)) {
            updateReview();
            showStep(current + 1);
            steps[current].querySelector('input,select,textarea')?.focus({ preventScroll: true });
        }
    });
    back.addEventListener('click', () => showStep(current - 1));
    form.addEventListener('input', updateReview);
    form.addEventListener('change', updateReview);
    form.addEventListener('submit', (event) => {
        updateReview();
        const firstInvalidStep = steps.findIndex((step) => [...step.querySelectorAll('input,select,textarea')].some((field) => !field.checkValidity()));
        if (firstInvalidStep !== -1) {
            event.preventDefault();
            showStep(firstInvalidStep);
            validateStep(firstInvalidStep);
        }
    });
    updateReview();
    showStep(current);
})();
</script>
@endpush
