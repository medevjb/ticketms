@extends('customer.layouts.app')
@section('title', 'My requests')
@section('content')
<header class="customer-page-heading"><div><span class="customer-kicker" style="color:#0e7490">Your support history</span><h1>My requests</h1><p>Find a ticket, follow its progress, or continue the conversation.</p></div><a class="customer-button" href="{{ route('customer.tickets.create') }}">＋ Create a ticket</a></header>
<section class="customer-panel">
    <form method="GET" action="{{ route('customer.tickets.index') }}" class="customer-filter"><label class="sr-only" for="ticket-search">Search requests</label><input id="ticket-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search ticket number or subject"><label class="sr-only" for="ticket-status">Filter by status</label><select id="ticket-status" name="status"><option value="active" @selected(($filters['status'] ?? 'active') === 'active')>Active requests</option><option value="all" @selected(($filters['status'] ?? '') === 'all')>All requests</option><option value="resolved" @selected(($filters['status'] ?? '') === 'resolved')>Resolved</option><option value="closed" @selected(($filters['status'] ?? '') === 'closed')>Closed</option></select><button class="customer-button customer-button-quiet" type="submit">Apply filters</button></form>
    @if($tickets->isEmpty())
        <div class="customer-empty"><span class="customer-empty-icon" aria-hidden="true">⌕</span><h3>No requests found</h3><p>Try another search or create a new support ticket.</p><a class="customer-button" href="{{ route('customer.tickets.create') }}">Create a ticket</a></div>
    @else
        @php($statusLabels = ['open' => 'Open', 'in_progress' => 'In progress', 'waiting_customer' => 'Waiting for you', 'resolved' => 'Resolved', 'closed' => 'Closed'])
        <div class="customer-table-wrap"><table class="customer-table"><thead><tr><th>Request</th><th>Category</th><th>Priority</th><th>Status</th><th>Last updated</th></tr></thead><tbody>
            @foreach($tickets as $ticket)
                <tr><td><a class="customer-table-title" href="{{ route('customer.tickets.show', $ticket) }}">{{ $ticket->number }} · {{ $ticket->subject }}</a><span class="customer-table-subtitle">Created {{ $ticket->created_at?->timezone('Asia/Dhaka')->format('d M Y') }} Dhaka time</span></td><td>{{ $ticket->category?->name ?? '—' }}</td><td>{{ $ticket->priority?->name ?? '—' }}</td><td><span class="customer-badge customer-badge-{{ $ticket->status->value }}">{{ $statusLabels[$ticket->status->value] ?? str($ticket->status->value)->headline() }}</span></td><td>{{ $ticket->updated_at?->timezone('Asia/Dhaka')->format('d M Y, h:i A') }}</td></tr>
            @endforeach
        </tbody></table></div>
        <div class="customer-panel-body">{{ $tickets->links() }}</div>
    @endif
</section>
@endsection
