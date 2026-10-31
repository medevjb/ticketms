@extends('customer.layouts.app')
@section('title', $ticket->number)
@section('content')
@php($statusLabels = ['open' => 'Open', 'in_progress' => 'In progress', 'waiting_customer' => 'Waiting for you', 'resolved' => 'Resolved', 'closed' => 'Closed'])
<header class="customer-page-heading"><div><span class="customer-kicker" style="color:#0e7490">Support request</span><h1>{{ $ticket->number }}</h1><p>{{ $ticket->subject }}</p></div><a class="customer-button customer-button-quiet" href="{{ route('customer.tickets.index') }}">← All requests</a></header>
@include('customer.service-status')
<div class="customer-ticket-grid" data-customer-ticket-id="{{ $ticket->id }}" data-ticket-status="{{ $ticket->status->value }}" data-customer-id="{{ $ticket->customer_id }}" data-current-user-id="{{ auth()->id() }}">
    <div style="display:grid;gap:1rem">
        <section class="customer-panel"><div class="customer-panel-heading"><div><h2>Request details</h2><p>Created {{ $ticket->created_at?->timezone('Asia/Dhaka')->format('d M Y, h:i A') }} Dhaka time</p></div><span class="customer-badge customer-badge-{{ $ticket->status->value }}">{{ $statusLabels[$ticket->status->value] ?? str($ticket->status->value)->headline() }}</span></div><div class="customer-panel-body"><p style="margin:0;white-space:pre-wrap;line-height:1.75">{{ $ticket->description }}</p>@if($mayEditContent)<div style="margin-top:1rem"><a class="customer-button customer-button-quiet" href="{{ route('customer.tickets.edit', $ticket) }}">Edit request details</a></div>@endif
            @if($ticket->attachments->isNotEmpty())<div style="margin-top:1.3rem"><strong style="font-size:.8rem">Files attached to this request</strong>@foreach($ticket->attachments as $attachment)<div style="display:flex;justify-content:space-between;gap:1rem;padding:.7rem 0;border-bottom:1px solid #e2e8f0;font-size:.82rem"><span>{{ $attachment->original_name }} · {{ number_format($attachment->size_bytes / 1024, 0) }} KiB</span>@if($attachment->is_active && !$attachment->trashed())<a class="customer-link" href="{{ route('customer.ticket-attachments.download', $attachment) }}">Download</a>@else<span style="color:#94a3b8">Unavailable</span>@endif</div>@endforeach</div>@endif
        </div></section>

        <section class="customer-panel customer-conversation-panel"><div class="customer-panel-heading"><div><h2>Conversation</h2><p>Replies from you and the support team.</p></div><span class="customer-conversation-count">{{ $messages->count() }} {{ \Illuminate\Support\Str::plural('message', $messages->count()) }}</span></div><div class="customer-panel-body customer-conversation-body" tabindex="0" role="region" aria-label="Ticket conversation messages">
            @include('admin.tickets.partials.initial-issue', ['timezone'=>'Asia/Dhaka', 'attachmentPreviewRoute'=>'customer.ticket-attachments.preview', 'attachmentDownloadRoute'=>'customer.ticket-attachments.download'])
            @foreach($messages as $message)
                @php($isCustomerMessage = (int) $message->author_id === (int) $ticket->customer_id)
                <article class="customer-chat-message {{ $isCustomerMessage ? 'customer-chat-message-self' : 'customer-chat-message-support' }}" data-message-id="{{ $message->getKey() }}">
                    <span class="customer-chat-avatar" aria-hidden="true">{{ str($message->author?->name ?? 'S')->substr(0, 1)->upper() }}</span>
                    <div class="customer-chat-content">
                        <header class="customer-chat-meta">
                            <div class="customer-chat-author"><strong>{{ $message->author?->name ?? 'Support' }}</strong><time>{{ $message->created_at?->timezone('Asia/Dhaka')->format('d M Y, h:i A') }} Dhaka time</time></div>
                            <span class="customer-chat-role">{{ $isCustomerMessage ? 'You' : 'Support team' }}</span>
                        </header>
                        @if(!$message->is_active)
                            <p class="customer-chat-bubble customer-chat-removed">This reply is no longer available.</p>
                        @else
                            <p class="customer-chat-bubble">{{ $message->body }}</p>
                            @foreach($message->attachments as $attachment)
                                @if($attachment->isPreviewableImage() && $attachment->is_active && !$attachment->trashed())
                                @include('admin.tickets.partials.attachment-preview', ['previewRoute'=>'customer.ticket-attachments.preview'])
                                @else
                                <div class="customer-chat-attachment"><span>{{ $attachment->original_name }} · {{ number_format($attachment->size_bytes / 1024, 0) }} KiB</span>@if($attachment->is_active && !$attachment->trashed())<a class="customer-link" href="{{ route('customer.ticket-attachments.download', $attachment) }}">Download</a>@else<span class="customer-chat-unavailable">Unavailable</span>@endif</div>
                                @endif
                            @endforeach
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
            @if($mayReply)
                <div class="customer-chat-composer">
                    @if($ticket->status->value === 'waiting_customer')
                        <p class="customer-chat-compose-note"><span aria-hidden="true">◷</span> Your reply resumes the ticket and its SLA clock.</p>
                    @elseif($ticket->status->value === 'resolved')
                        <p class="customer-chat-compose-note customer-chat-compose-note-reopen"><span aria-hidden="true">↻</span> Add a reason to reopen this request with your reply.</p>
                    @endif
                    <form class="customer-form customer-chat-composer-form" method="POST" enctype="multipart/form-data" action="{{ route('customer.tickets.messages.store', $ticket) }}">
                        @csrf
                        <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <input type="hidden" name="lock_version" value="{{ $ticket->lock_version }}">
                        <input type="hidden" name="visibility" value="public">
                        @if($ticket->status->value === 'resolved')
                            <div class="customer-chat-reopen-fields">
                                <label class="customer-field-label" for="reopen-reason">Reason for reopening</label>
                                <textarea class="customer-field" id="reopen-reason" name="reason" rows="2" minlength="5" maxlength="500" required>{{ old('reason') }}</textarea>

                                <label class="customer-chat-reopen-check"><input type="checkbox" name="reopen" value="1" required @checked(old('reopen'))> Reopen this request with my reply</label>

                            </div>
                        @endif
                        <div class="customer-chat-compose-box">
                            <textarea class="customer-chat-textarea" id="reply-body" name="body" rows="3" minlength="1" maxlength="20000" required placeholder="Write a message…">{{ old('body') }}</textarea>

                            <div class="customer-chat-compose-toolbar">
                                <label class="customer-chat-attach-button" for="reply-attachments" title="Attach files">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m21.4 11.1-8.5 8.5a5.5 5.5 0 0 1-7.8-7.8l9.2-9.2a3.7 3.7 0 0 1 5.2 5.2l-9.2 9.2a1.9 1.9 0 0 1-2.6-2.6l8.5-8.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <span>Attach files</span>
                                    <input class="customer-chat-file-input" id="reply-attachments" name="attachments[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.txt,.csv">
                                </label>
                                <span class="customer-chat-file-status" data-chat-file-status aria-live="polite">No files</span>
                                <span class="customer-chat-compose-spacer"></span>
                                <button class="customer-chat-send-button" type="submit"><span>Send reply</span><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m22 2-7 20-4-9-9-4 20-7ZM22 2 11 13" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                            </div>
                        </div>
                        <p class="customer-chat-compose-hint">Up to 5 files, 10 MiB each · PDF, JPEG, PNG, TXT, or CSV</p>


                    </form>
                </div>
            @endif
        </section>
    </div>

    <aside class="customer-ticket-sidebar">
        <section class="customer-panel"><div class="customer-panel-heading"><h2>At a glance</h2></div><div class="customer-panel-body"><dl class="customer-ticket-meta"><div><dt>Priority</dt><dd data-ticket-priority>{{ $ticket->priority?->name ?? '—' }}</dd></div><div><dt>Category</dt><dd>{{ $ticket->category?->name ?? '—' }}</dd></div><div><dt>Created</dt><dd>{{ $ticket->created_at?->timezone('Asia/Dhaka')->format('d M Y') }} · Dhaka time</dd></div></dl></div></section>
        <section class="customer-panel"><div class="customer-panel-heading"><h2>Your resolution target</h2></div><div class="customer-panel-body" data-sla-countdown="{{ json_encode($sla) }}" data-sla-ticket="{{ $ticket->id }}" data-sla-audience="customer">
            <span class="customer-badge" data-sla-badge><span data-sla-status>{{ str($sla['state'])->headline() }}</span></span>
            <p class="customer-resolution-clock" data-sla-clock role="timer">{{ sprintf('%02d:%02d:%02d', intdiv($sla['remaining_seconds'], 3600), intdiv($sla['remaining_seconds'] % 3600, 60), $sla['remaining_seconds'] % 60) }}</p>
            <p class="customer-field-hint" data-sla-clock-note>Business time remaining</p>
            @if($sla['due_at'])<p class="customer-resolution-deadline"><strong>Expected resolution by</strong><time datetime="{{ $sla['due_at']->toIso8601String() }}">{{ $sla['due_at']->timezone('Asia/Dhaka')->format('D, d M Y · h:i A') }} Dhaka time</time></p>
            @elseif($sla['state'] === 'paused')<p class="customer-resolution-deadline">We are waiting for your reply. Your response resumes the SLA clock and updates the deadline.</p>
            @elseif($sla['state'] === 'stopped')<p class="customer-resolution-deadline">This request is {{ $ticket->status->value }}. The resolution clock has stopped.</p>@endif
            <p class="customer-field-hint">This target covers resolution, not the first reply. Non-working hours and holidays do not count. If the target has passed, check the conversation for the latest update.</p>
        </div></section>
        @if(in_array(\App\Enums\TicketTransition::Close, $transitions, true))<section class="customer-panel"><div class="customer-panel-body"><h2 style="margin:0 0 .4rem;font-size:.95rem">All set?</h2><p style="margin:0 0 .9rem;color:#64748b;font-size:.8rem;line-height:1.5">Close this request when the issue has been resolved for you.</p><form method="POST" action="{{ route('customer.tickets.transition', $ticket) }}">@csrf @method('PATCH')<input type="hidden" name="transition" value="close"><input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="lock_version" value="{{ $ticket->lock_version }}"><button class="customer-button customer-button-quiet" type="submit">Close request</button></form></div></section>@endif
    </aside>
</div>
@push('styles')
<style>
    .customer-conversation-body{display:grid;gap:.15rem;padding:.85rem 1rem!important}
    .customer-chat-message{align-items:start;gap:.75rem;padding:.85rem .65rem;border-bottom:1px solid #e8edf3}
    .customer-chat-content{width:100%;min-width:0;max-width:720px}
    .customer-chat-meta{width:100%;align-items:flex-start;justify-content:space-between;gap:.55rem}
    .customer-chat-author{min-width:0}
    .customer-chat-message-support{grid-template-columns:minmax(0,1fr) 2.15rem}
    .customer-chat-message-support .customer-chat-avatar{grid-column:2;grid-row:1}
    .customer-chat-message-support .customer-chat-content{grid-column:1;grid-row:1;justify-self:end;align-items:flex-end}
    .customer-chat-message-support .customer-chat-meta{flex-direction:row;justify-content:flex-end;align-items:flex-start}
    .customer-chat-message-support .customer-chat-author{text-align:right}
    .customer-chat-message-support .customer-chat-bubble{align-self:flex-end;margin-right:0}
    .customer-chat-message-self{grid-template-columns:minmax(0,1fr) 2.15rem;margin:0;border:0;border-bottom:1px solid #e8edf3;border-radius:0;padding:.85rem 0;background:transparent}
    .customer-chat-message-self .customer-chat-avatar{grid-column:2;grid-row:1;justify-self:end;border-color:#a5f3fc;background:#ecfeff;color:#0e7490}
    .customer-chat-message-self .customer-chat-content{grid-column:1;grid-row:1;justify-self:end;align-items:stretch;width:min(100%,700px);max-width:84%;padding:.85rem .95rem;border:1px solid #bae6fd;border-radius:14px;background:#f0f9ff;box-shadow:0 4px 14px #0f172a0a}
    .customer-chat-message-self .customer-chat-meta{align-items:center;padding-bottom:.6rem;border-bottom:1px solid #dbeafe}
    .customer-chat-message-self .customer-chat-author{text-align:left}
    .customer-chat-message-self .customer-chat-role{background:#dbeafe;color:#1d4ed8}
    .customer-chat-message-self .customer-chat-bubble{width:100%;max-width:none;align-self:stretch;margin:.65rem 0 0;padding:0;border:0;border-radius:0;background:transparent;color:#1e293b}
    .customer-chat-message-self .customer-chat-attachment{width:100%;max-width:none;margin-top:.6rem;border-color:#dbeafe;background:#fff}
    .customer-chat-message-support .customer-chat-attachment{align-self:flex-end}
    .customer-chat-composer{border-top:1px solid #e2e8f0;padding:1rem 1.15rem 1.1rem;background:linear-gradient(180deg,#fbfdff 0%,#f7fafc 100%)}
    .customer-chat-composer-form{gap:.55rem}
    .customer-chat-compose-note{display:flex;align-items:center;gap:.45rem;margin:0 0 .15rem;color:#64748b;font-size:.76rem;line-height:1.5}
    .customer-chat-compose-note>span{color:#0e7490;font-size:.95rem}
    .customer-chat-compose-note-reopen{color:#9a5a08}
    .customer-chat-compose-note-reopen>span{color:#b7791f}
    .customer-chat-reopen-fields{display:grid;gap:.45rem;padding:.75rem;border:1px solid #fde2b7;border-radius:.8rem;background:#fffaf0}
    .customer-chat-reopen-fields .customer-field{min-height:62px;background:#fff}
    .customer-chat-reopen-check{display:flex;align-items:center;gap:.45rem;color:#7c5a26;font-size:.76rem;font-weight:650}
    .customer-chat-compose-box{overflow:hidden;border:1px solid #cbd5e1;border-radius:1rem;background:#fff;box-shadow:0 2px 8px #0f172a08;transition:border-color .16s ease,box-shadow .16s ease}
    .customer-chat-compose-box:focus-within{border-color:#0891b2;box-shadow:0 0 0 3px #06b6d422,0 4px 14px #0f172a0b}
    .customer-chat-textarea{display:block;width:100%;min-height:74px;max-height:240px;resize:vertical;border:0;padding:.8rem .9rem;background:transparent;color:#0f172a;font:inherit;font-size:.88rem;line-height:1.55;outline:0}
    .customer-chat-textarea::placeholder{color:#94a3b8}
    .customer-chat-compose-toolbar{display:flex;align-items:center;gap:.6rem;padding:.55rem .65rem;border-top:1px solid #f1f5f9}
    .customer-chat-attach-button{position:relative;display:inline-flex;align-items:center;gap:.4rem;min-height:36px;padding:.45rem .6rem;border-radius:.65rem;color:#475569;font-size:.75rem;font-weight:700;cursor:pointer;transition:background .15s ease,color .15s ease}
    .customer-chat-attach-button:hover,.customer-chat-attach-button:focus-within{background:#ecfeff;color:#0e7490}
    .customer-chat-attach-button svg,.customer-chat-send-button svg{width:1rem;height:1rem;flex:none}
    .customer-chat-file-input{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap}
    .customer-chat-file-status{overflow:hidden;max-width:230px;color:#64748b;font-size:.7rem;text-overflow:ellipsis;white-space:nowrap}
    .customer-chat-compose-spacer{flex:1}
    .customer-chat-send-button{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;min-height:38px;padding:.55rem .85rem;border:0;border-radius:.7rem;background:#0e7490;color:#fff;font:inherit;font-size:.76rem;font-weight:750;cursor:pointer;transition:background .15s ease,transform .15s ease}
    .customer-chat-send-button:hover{transform:translateY(-1px);background:#155e75}
    .customer-chat-send-button:focus-visible{outline:3px solid #67e8f9;outline-offset:2px}
    .customer-chat-send-button:disabled{opacity:.65;cursor:wait;transform:none}
    .customer-chat-compose-hint{margin:0;color:#94a3b8;font-size:.67rem;line-height:1.4}
    .customer-chat-composer .customer-field-error{margin:0;padding:.1rem .1rem}
    @media(max-width:560px){.customer-conversation-body{padding:.65rem!important}.customer-chat-message{grid-template-columns:1.8rem minmax(0,1fr);gap:.55rem;padding:.75rem .45rem}.customer-chat-message-support{grid-template-columns:minmax(0,1fr) 1.8rem}.customer-chat-content{max-width:100%}.customer-chat-meta{flex-wrap:wrap;gap:.3rem}.customer-chat-author time{line-height:1.35}.customer-chat-message-support .customer-chat-meta{justify-content:flex-end}.customer-chat-bubble{max-width:100%}.customer-chat-message-self{grid-template-columns:minmax(0,1fr) 1.8rem}.customer-chat-message-self .customer-chat-content{max-width:100%;padding:.7rem .75rem}.customer-chat-message-self .customer-chat-meta{justify-content:space-between}.customer-chat-message-self .customer-chat-bubble{max-width:none}}
    html.dark .customer-chat-message-self .customer-chat-content{border-color:#315766;background:#1b3340;box-shadow:0 4px 14px #00000018}
    html.dark .customer-chat-message-self .customer-chat-meta{border-color:#315766}
    html.dark .customer-chat-message-self .customer-chat-bubble{color:#d4edf0}
    html.dark .customer-chat-message-self .customer-chat-attachment{border-color:#315766;background:#172231}
    @media(max-width:560px){.customer-chat-composer{padding:.8rem}.customer-chat-textarea{min-height:66px;padding:.7rem}.customer-chat-compose-toolbar{gap:.3rem;padding:.45rem}.customer-chat-attach-button{gap:.3rem;padding:.4rem .45rem;font-size:.68rem}.customer-chat-file-status{max-width:105px;font-size:.64rem}.customer-chat-send-button{min-height:36px;padding:.5rem .65rem;font-size:.7rem}.customer-chat-send-button span{max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}}
</style>
@endpush
@push('scripts')
@vite('resources/js/ticket-operations.ts')
@endpush
@endsection
