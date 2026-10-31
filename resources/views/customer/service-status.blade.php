@php($availability = $serviceAvailability ?? ($slaOverview['availability'] ?? null))
@if($availability)
<section class="customer-service-status {{ $availability['is_open'] ? 'is-open' : 'is-closed' }}" data-service-status aria-label="Support availability">
    <span class="customer-service-status-icon" aria-hidden="true">◷</span>
    <div><strong data-service-status-message>{{ $availability['message'] }}</strong>
        <p data-service-status-detail>@if($availability['is_open'])Requests count toward their resolution target during scheduled service hours.@elseif($availability['next_open_label'])You can submit a ticket now. Service resumes {{ $availability['next_open_label'] }} · {{ $availability['timezone'] }}. Non-working hours do not consume SLA time.@elseYou can still submit a ticket. Your service target will be confirmed after submission.@endif</p>
    </div>
    <span class="customer-service-status-zone" data-service-timezone>{{ $availability['timezone'] }}</span>
</section>
@endif
