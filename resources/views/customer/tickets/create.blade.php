@extends('customer.layouts.app')
@section('title', 'Create a ticket')
@section('content')
<header class="customer-page-heading"><div><span class="customer-kicker" style="color:#0e7490">Your support request</span><h1>Create a support ticket</h1><p>Share the details that will help us understand your request.</p></div><a class="customer-button customer-button-quiet" href="{{ route('customer.tickets.index') }}">My requests</a></header>
<div class="customer-create-availability" data-customer-sla-create data-sla-initial="{{ json_encode($slaOverview) }}">@include('customer.service-status')</div>
<section class="customer-panel" style="max-width:820px;margin:0 auto">
    <div class="customer-wizard-steps" aria-label="Ticket creation progress"><div class="customer-wizard-step is-current" data-wizard-indicator="0"><span>1</span>Details</div><i class="customer-wizard-line"></i><div class="customer-wizard-step" data-wizard-indicator="1"><span>2</span>Priority & files</div><i class="customer-wizard-line"></i><div class="customer-wizard-step" data-wizard-indicator="2"><span>3</span>Review</div></div>
    <div class="customer-wizard-content">
        <form id="customer-ticket-form" method="POST" enctype="multipart/form-data" action="{{ route('customer.tickets.store') }}">
            @csrf<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <section class="customer-wizard-section" data-wizard-step="0" data-has-errors="{{ collect($errors->keys())->intersect(['subject', 'description', 'category_id'])->isNotEmpty() ? 'true' : 'false' }}"><span class="customer-kicker" style="color:#0e7490">STEP 1 OF 3</span><h2>Tell us what you need</h2><p>A short summary and a little context make a good start.</p>
                <div class="customer-field-wrap"><label class="customer-field-label" for="subject">Subject <span aria-hidden="true">*</span></label><input class="customer-field" id="subject" name="subject" maxlength="255" required value="{{ old('subject') }}" placeholder="A short summary of the issue"></div>
                <div class="customer-field-wrap"><label class="customer-field-label" for="description">What happened? <span aria-hidden="true">*</span></label><textarea class="customer-field" id="description" name="description" rows="6" maxlength="20000" required placeholder="Share what you were trying to do and what happened.">{{ old('description') }}</textarea><p class="customer-field-hint">Include any steps or details that could help us understand the issue.</p></div>
                <div class="customer-field-wrap"><label class="customer-field-label" for="category_id">Category <span aria-hidden="true">*</span></label><select class="customer-field" id="category_id" name="category_id" required><option value="">Choose the closest match</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></div>
            </section>
            <section class="customer-wizard-section" data-wizard-step="1" data-has-errors="{{ collect($errors->keys())->contains(fn ($key) => $key === 'priority_id' || str_starts_with($key, 'attachments') || $key === 'sla') ? 'true' : 'false' }}"><span class="customer-kicker" style="color:#0e7490">STEP 2 OF 3</span><h2>Priority and helpful files</h2><p>Choose the level that best reflects the impact to you.</p>
                <div class="customer-field-wrap"><label class="customer-field-label" for="priority_id">Priority <span aria-hidden="true">*</span></label><select class="customer-field" id="priority_id" name="priority_id" required><option value="">Choose a priority</option>@foreach($priorities as $priority)<option value="{{ $priority->id }}" @selected((string) old('priority_id') === (string) $priority->id)>{{ $priority->name }}</option>@endforeach</select></div>
                <section class="customer-resolution-estimate" aria-live="polite">
                    <span>Estimated resolution if submitted now</span>
                    <strong data-sla-estimated-time>Choose a priority to see your expected target</strong>
                    <p data-sla-estimated-duration>Scheduled business hours and holidays are included in this estimate.</p>
                    <small>This is a resolution target, not a guaranteed first reply. The final deadline is calculated when you submit.</small>
                </section>
                <div class="customer-field-wrap"><label class="customer-field-label" for="attachments">Attachments <span style="font-weight:500;color:#64748b">Optional</span></label><input class="customer-field" id="attachments" type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.txt,.csv"><p class="customer-field-hint">Up to 5 private files, 10 MiB each: PDF, JPEG, PNG, TXT or CSV.</p> </div>
                @if($slaOverview)<div class="customer-sla-callout"><strong>Service hours · {{ $slaOverview['timezone'] }}</strong>{{ collect($slaOverview['windows'])->map(fn ($window) => $window['day'].' '.$window['hours'])->join(' · ') }}<br>Resolution targets count scheduled business time, excluding configured holidays. Waiting for your reply pauses the SLA clock; your response resumes it.</div>@else<div class="customer-sla-callout"><strong>How service targets work</strong>Resolution targets count during configured business hours and exclude holidays. Waiting for your reply pauses the SLA clock; your response resumes it.</div>@endif

            </section>
            <section class="customer-wizard-section" data-wizard-step="2"><span class="customer-kicker" style="color:#0e7490">STEP 3 OF 3</span><h2>Review before sending</h2><p>Check your request. You can go back to make changes.</p><dl class="customer-review"><div><dt>Subject</dt><dd data-review="subject">Not provided</dd></div><div><dt>Category</dt><dd data-review="category_id">Not selected</dd></div><div><dt>Priority</dt><dd data-review="priority_id">Not selected</dd></div><div><dt>Estimated resolution</dt><dd data-review-sla>Choose a priority</dd></div><div><dt>Description</dt><dd data-review="description">Not provided</dd></div><div><dt>Files</dt><dd data-review="attachments">None attached</dd></div></dl><div class="customer-sla-callout"><strong>Your request stays in your support space</strong>We’ll keep updates and replies together so you can follow the conversation.</div></section>
            <div class="customer-wizard-actions"><a class="customer-button customer-button-quiet" href="{{ route('customer.tickets.index') }}">Cancel</a><div style="display:flex;gap:.6rem"><button class="customer-button customer-button-quiet" type="button" data-wizard-back hidden>Back</button><button class="customer-button" type="button" data-wizard-next hidden>Continue</button><button class="customer-button" type="submit" data-wizard-submit>Submit ticket</button></div></div>
        </form>
    </div>
</section>
@endsection
@push('scripts')
@vite('resources/js/customer-sla-preview.ts')
<script>
(() => {
    const form = document.getElementById('customer-ticket-form');
    if (!form) return;
    const sections = [...form.querySelectorAll('[data-wizard-step]')];
    const indicators = [...document.querySelectorAll('[data-wizard-indicator]')];
    const back = form.querySelector('[data-wizard-back]');
    const next = form.querySelector('[data-wizard-next]');
    const submit = form.querySelector('[data-wizard-submit]');
    let current = sections.findIndex((section) => section.dataset.hasErrors === 'true');
    if (current < 0) current = 0;
    form.noValidate = true;
    const show = (step) => {
        current = Math.max(0, Math.min(step, sections.length - 1));
        sections.forEach((section, index) => { section.hidden = index !== current; });
        indicators.forEach((indicator, index) => {
            indicator.classList.toggle('is-current', index === current);
            indicator.classList.toggle('is-complete', index < current);
        });
        back.hidden = current === 0;
        next.hidden = current === sections.length - 1;
        submit.hidden = current !== sections.length - 1;
    };
    const review = () => {
        ['subject', 'description'].forEach((name) => {
            const field = form.elements.namedItem(name);
            form.querySelector(`[data-review="${name}"]`).textContent = field.value.trim() || 'Not provided';
        });
        ['category_id', 'priority_id'].forEach((name) => {
            const field = form.elements.namedItem(name);
            form.querySelector(`[data-review="${name}"]`).textContent = field.selectedOptions[0]?.textContent || 'Not selected';
        });
        const files = form.querySelector('#attachments').files;
        form.querySelector('[data-review="attachments"]').textContent = files.length ? [...files].map((file) => file.name).join(', ') : 'None attached';
    };
    const valid = () => {
        const field = [...sections[current].querySelectorAll('input,select,textarea')].find((control) => !control.checkValidity());
        if (!field) return true;
        field.reportValidity();
        field.focus();
        return false;
    };
    next.addEventListener('click', () => { if (valid()) { review(); show(current + 1); } });
    back.addEventListener('click', () => show(current - 1));
    form.addEventListener('input', review);
    form.addEventListener('change', review);
    form.addEventListener('submit', (event) => {
        review();
        const invalid = sections.findIndex((section) => [...section.querySelectorAll('input,select,textarea')].some((field) => !field.checkValidity()));
        if (invalid >= 0) { event.preventDefault(); show(invalid); valid(); }
    });
    review();
    show(current);
})();
</script>
@endpush
