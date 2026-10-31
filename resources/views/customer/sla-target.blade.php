@if($ticket->status->value === 'waiting_customer')
    <span class="customer-target-label">Waiting for your reply</span><small class="customer-target-note">SLA paused until you respond</small>
@elseif(in_array($ticket->status->value, ['resolved','closed'], true))
    <span class="customer-target-label">{{ $ticket->status->value === 'closed' ? 'Closed' : 'Resolved' }}</span><small class="customer-target-note">Resolution clock stopped</small>
@elseif($ticket->sla_due_at)
    <span class="customer-target-label {{ $ticket->sla_due_at->isPast() ? 'is-overdue' : '' }}">{{ $ticket->sla_due_at->timezone('Asia/Dhaka')->format('d M, h:i A') }}</span><small class="customer-target-note">{{ $ticket->sla_due_at->isPast() ? 'Target passed · follow the conversation for updates' : 'Resolution target · Dhaka time' }}</small>
@else
    <span class="customer-target-label">Target pending</span>
@endif
