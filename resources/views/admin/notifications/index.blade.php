@extends('tyro-dashboard::layouts.admin')
@section('title', 'Notifications')
@section('breadcrumb')
    <a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a>
    <span class="breadcrumb-separator">/</span>
    <span>Notifications</span>
@endsection
@section('content')
    <div class="page-header">
        <div class="page-header-row">
            <div>
                <h1 class="page-title">Notifications</h1>
                <p class="page-description">Ticket updates that match your current access.</p>
            </div>
            @if($notifications->getCollection()->contains(fn ($notification): bool => $notification->read_at === null))
                <form method="POST" action="{{ route('dashboard.notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Mark all read</button>
                </form>
            @endif
        </div>
    </div>

    @forelse($notifications as $notification)
        @php($data = $notification->data)
        <article class="card" style="margin-bottom: .75rem">
            <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                <div style="min-width:0;flex:1">
                    <p>
                        <strong>{{ $data['ticket_number'] ?? 'Ticket update' }}</strong>
                        @if($notification->read_at === null)
                            <span class="status-badge">Unread</span>
                        @endif
                    </p>
                    <p class="page-description">{{ $data['summary'] ?? 'There is an update on a ticket you can access.' }}</p>
                    <p class="page-description">{{ $notification->created_at?->timezone('Asia/Dhaka')->format('d M Y, h:i A') }} Dhaka time</p>
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                    @if(isset($data['ticket_id']))
                        <a class="btn btn-secondary" href="{{ route('dashboard.tickets.show', (int) $data['ticket_id']) }}">View ticket</a>
                    @endif
                    @if($notification->read_at === null)
                        <form method="POST" action="{{ route('dashboard.notifications.read', $notification->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-ghost">Mark read</button>
                        </form>
                    @endif
                </div>
            </div>
        </article>
    @empty
        <section class="card">
            <div class="card-body">
                <p class="page-description">No ticket notifications are available.</p>
            </div>
        </section>
    @endforelse

    <div style="margin-top: 1rem">{{ $notifications->links() }}</div>
@endsection
