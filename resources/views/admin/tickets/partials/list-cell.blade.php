@switch($column)
    @case('selection')
        <input type="checkbox" name="selected_ids[]" value="{{ $ticket->id }}" form="ticket-bulk-form" class="ticket-select" aria-label="Select ticket {{ $ticket->number }}">
        <input type="hidden" name="expected_versions[{{ $ticket->id }}]" value="{{ $ticket->lock_version }}" form="ticket-bulk-form">
        @break
    @case('ticket')
        <a class="ticket-number" href="{{ route('dashboard.tickets.show', $ticket) }}">{{ $ticket->number }}</a>
        @break
    @case('subject')
        <a title="{{ $ticket->subject }}" class="ticket-subject" href="{{ route('dashboard.tickets.show', $ticket) }}">{{ str($ticket->subject)->limit(64) }}</a>
        @if(!$ticket->is_active)<span class="ticket-chip ticket-chip-muted">Disabled</span>@endif
        @break
    @case('status')
        <span class="ticket-chip ticket-status-{{ $ticket->status->value }}">{{ str($ticket->status->value)->replace('_', ' ')->title() }}</span>
        @break
    @case('priority')
        <span class="ticket-chip ticket-priority-{{ in_array($ticket->priority?->slug, ['critical','high','medium','low'], true) ? $ticket->priority->slug : 'custom' }}"><span class="ticket-chip-dot" aria-hidden="true"></span><span title="{{ $ticket->priority?->name ?? 'Unknown' }}">{{ str($ticket->priority?->name ?? 'Unknown')->limit(28) }}</span></span>
        @break
    @case('category')
        <span title="{{ $ticket->category?->name ?? 'Unknown' }}">{{ str($ticket->category?->name ?? 'Unknown')->limit(36) }}</span>
        @break
    @case('customer')
        <span title="{{ $ticket->customer?->name ?? 'Unavailable' }}">{{ str($ticket->customer?->name ?? 'Unavailable')->limit(32) }}</span>
        @break
    @case('assignee')
        @if($ticket->assigned_agent_id === null)
            <span class="ticket-chip ticket-chip-unassigned">Unassigned</span>
            @if(in_array($ticket->status->value, ['open','in_progress','waiting_customer'], true))<small class="ticket-cell-hint">Needs assignment</small>@endif
        @else
            <span title="{{ $ticket->assignedAgent?->name ?? 'Unavailable' }}">{{ str($ticket->assignedAgent?->name ?? 'Unavailable')->limit(32) }}</span>
        @endif
        @break
    @case('sla')
        @if($ticket->status->value === 'waiting_customer')<span class="ticket-chip ticket-chip-muted">Paused</span>
        @elseif(in_array($ticket->status->value, ['resolved','closed'], true))<span class="ticket-chip ticket-chip-muted">Stopped</span>
        @elseif($ticket->sla_due_at && $ticket->sla_due_at->isPast())<span class="ticket-chip ticket-sla-breached">Breached</span>
        @elseif($ticket->sla_warning_at && $ticket->sla_warning_at->isPast())<span class="ticket-chip ticket-chip-unassigned">Approaching</span>
        @else<span class="ticket-chip ticket-sla-within">Within SLA</span>@endif
        @if($ticket->sla_due_at && in_array($ticket->status->value, ['open','in_progress'], true))<small class="ticket-cell-hint">Due {{ $ticket->sla_due_at->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M, h:i A') }}</small>@endif
        @break
    @case('created')
        <span class="ticket-date">{{ $ticket->created_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M Y') }}</span><small class="ticket-cell-hint">{{ $ticket->created_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('h:i A') }}</small>
        @break
    @case('actions')
        <a class="btn btn-ghost admin-icon-button" href="{{ route('dashboard.tickets.show', $ticket) }}" title="View ticket {{ $ticket->number }}" aria-label="View ticket {{ $ticket->number }}">@include('admin.action-icon', ['icon' => 'view'])</a>
        @break
@endswitch
