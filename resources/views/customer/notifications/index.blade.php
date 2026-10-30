@extends('customer.layouts.app')
@section('title', 'Updates')
@section('content')
<header class="customer-page-heading"><div><span class="customer-kicker" style="color:#0e7490">Keep up to date</span><h1>Notifications</h1><p>Updates about your support requests.</p></div>@if($notifications->whereNull('read_at')->isNotEmpty())<form method="POST" action="{{ route('customer.notifications.read-all') }}">@csrf<button class="customer-button customer-button-quiet" type="submit">Mark all as read</button></form>@endif</header>
<section class="customer-panel"><div class="customer-panel-body">
    @forelse($notifications as $notification)
        @php($data = $notification->data ?? [])
        <article class="customer-notice"><div><h3>{{ $data['ticket_number'] ?? 'Support update' }} @if(!$notification->read_at)<span class="customer-badge customer-badge-open">New</span>@endif</h3><p>{{ $data['summary'] ?? 'There is an update to your support request.' }} · {{ $notification->created_at?->timezone('Asia/Dhaka')->format('d M Y, h:i A') }} Dhaka time</p><a class="customer-link" href="{{ route('customer.tickets.show', (int) ($data['ticket_id'] ?? 0)) }}">Open request →</a></div>@if(!$notification->read_at)<form method="POST" action="{{ route('customer.notifications.read', $notification->id) }}">@csrf<button type="submit" class="customer-button customer-button-quiet">Mark read</button></form>@endif</article>
    @empty<div class="customer-empty"><span class="customer-empty-icon" aria-hidden="true">✓</span><h3>You’re all caught up</h3><p>When there’s a new update to your request, it will appear here.</p></div>@endforelse
    @if($notifications->isNotEmpty())<div style="padding-top:1rem">{{ $notifications->links() }}</div>@endif
</div></section>
@endsection
