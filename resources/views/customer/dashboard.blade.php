@extends('customer.layouts.app')
@section('title', 'My support')
@section('content')
<section class="customer-welcome">
    <div class="customer-welcome-content">
        <span class="customer-kicker">Your support space</span>
        <h1>Hello, {{ $customer->name }}.</h1>
        <p>Keep your requests and conversations together. We’ll make the next step clear as your support moves forward.</p>
        <div style="display:flex;flex-wrap:wrap;gap:.7rem;margin-top:1.25rem"><a class="customer-button customer-button-light" href="{{ route('customer.tickets.create') }}">＋ Create a ticket</a><a class="customer-button" href="{{ route('customer.tickets.index') }}" style="border:1px solid #ffffff44;background:#ffffff12">View my requests</a></div>
    </div>
    <div class="customer-welcome-art" aria-hidden="true"><span>✳</span><small>We’re here<br>to help.</small></div>
</section>

@include('customer.service-status')

<section class="customer-stats" aria-label="Your ticket summary">
    <article class="customer-stat"><span class="customer-stat-label">All requests</span><div class="customer-stat-value">{{ $overview['total'] }}</div><p class="customer-stat-note">Tickets you have created</p></article>
    <article class="customer-stat"><span class="customer-stat-label">In progress</span><div class="customer-stat-value">{{ $overview['active'] }}</div><p class="customer-stat-note">Being worked on by support</p></article>
    <article class="customer-stat"><span class="customer-stat-label">Waiting for you</span><div class="customer-stat-value">{{ $overview['waiting'] }}</div><p class="customer-stat-note">A reply will resume the SLA clock</p></article>
    <article class="customer-stat"><span class="customer-stat-label">Completed</span><div class="customer-stat-value">{{ $overview['completed'] }}</div><p class="customer-stat-note">Resolved or closed requests</p></article>
</section>

<section class="customer-panel">
    <div class="customer-panel-heading"><div><h2>Recent requests</h2><p>Your latest conversations with support.</p></div><a class="customer-link" href="{{ route('customer.tickets.index') }}">All requests →</a></div>
    @if($overview['recent']->isEmpty())
        <div class="customer-empty"><span class="customer-empty-icon" aria-hidden="true">✳</span><h3>Your support history starts here</h3><p>Create a ticket and keep the details, files, and replies in one conversation.</p><a class="customer-button" href="{{ route('customer.tickets.create') }}">Create your first ticket <span aria-hidden="true">→</span></a></div>
    @else
        @php($statusLabels = ['open' => 'Open', 'in_progress' => 'In progress', 'waiting_customer' => 'Waiting for you', 'resolved' => 'Resolved', 'closed' => 'Closed'])
        <div class="customer-table-wrap"><table class="customer-table"><thead><tr><th>Request</th><th>Category</th><th>Priority</th><th>Status</th><th>Resolution target</th><th>Created</th></tr></thead><tbody>
            @foreach($overview['recent'] as $ticket)
                <tr data-ticket-row="{{ $ticket->id }}"><td><a class="customer-table-title" href="{{ route('customer.tickets.show', $ticket) }}">{{ $ticket->number }} · <span title="{{ $ticket->subject }}">{{ str($ticket->subject)->limit(64) }}</span></a><span class="customer-table-subtitle">Open conversation</span></td><td><span title="{{ $ticket->category?->name ?? '—' }}">{{ str($ticket->category?->name ?? '—')->limit(36) }}</span></td><td><span title="{{ $ticket->priority?->name ?? '—' }}" data-ticket-priority>{{ str($ticket->priority?->name ?? '—')->limit(36) }}</span></td><td><span class="customer-badge customer-badge-{{ $ticket->status->value }}" data-ticket-status-badge>{{ $statusLabels[$ticket->status->value] ?? str($ticket->status->value)->headline() }}</span></td><td>@include('customer.sla-target')</td><td>{{ $ticket->created_at?->timezone('Asia/Dhaka')->format('d M Y') }}</td></tr>
            @endforeach
        </tbody></table></div>
    @endif
</section>

<section class="customer-sla-callout" style="margin-top:1rem"><strong>How resolution targets work</strong>Targets count during configured business hours. If a ticket is waiting for your reply, its SLA clock pauses and resumes when you respond. See the <a class="customer-link" href="{{ route('home') }}#service-levels">configured service levels</a>.</section>
@endsection
