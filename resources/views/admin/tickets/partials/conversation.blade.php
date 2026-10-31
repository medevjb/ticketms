@php($internalNoteCount = $messages->filter(fn ($message) => $message->visibility->value === 'internal')->count())
<div class="ticket-chat-workspace">
<section class="card ticket-panel ticket-conversation">
    <div class="ticket-panel-heading">
        <div><h2>Conversation</h2><p>Customer replies and support updates</p></div>
        <div class="ticket-conversation-summary">
            <span class="ticket-message-count">{{ $messages->count() }} {{ \Illuminate\Support\Str::plural('message', $messages->count()) }}</span>
            @if($internalNoteCount > 0)
                <span class="ticket-internal-count">{{ $internalNoteCount }} {{ \Illuminate\Support\Str::plural('staff note', $internalNoteCount) }}</span>
            @else
                <span class="ticket-internal-empty-label">No staff notes</span>
            @endif
        </div>
    </div>
    <div class="ticket-conversation-body" tabindex="0" role="region" aria-label="Ticket conversation messages">
        @include('admin.tickets.partials.initial-issue', ['timezone'=>config('ticketing.display_timezone', 'Asia/Dhaka'), 'attachmentPreviewRoute'=>'dashboard.ticket-attachments.preview', 'attachmentDownloadRoute'=>'dashboard.ticket-attachments.download'])
        @foreach($messages as $message)
            @if($message->visibility->value === 'internal')
                <article class="ticket-internal-note-row" data-message-id="{{ $message->getKey() }}">
                    <span class="ticket-internal-note-icon" aria-hidden="true">▣</span>
                    <div class="ticket-internal-note-copy">
                        <div class="ticket-internal-note-meta"><strong>{{ $message->author?->name ?? 'Former user' }}</strong><span>{{ $message->created_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M Y, h:i A') }}</span>@if($message->trashed())<span class="ticket-message-tag ticket-message-tag-muted">Archived</span>@elseif(!$message->is_active)<span class="ticket-message-tag ticket-message-tag-muted">Hidden</span>@endif</div>
                        <p>{{ str($message->body)->limit(150) }}</p>
                    </div>
                    <button type="button" class="ticket-internal-note-open" data-note-dialog="ticket-note-dialog-{{ $message->getKey() }}">View note <span aria-hidden="true">↗</span></button>
                </article>

                <dialog class="ticket-note-dialog" id="ticket-note-dialog-{{ $message->getKey() }}" aria-labelledby="ticket-note-title-{{ $message->getKey() }}" {{ (string) old('note_message_id') === (string) $message->getKey() ? 'data-open-on-load' : '' }}>
                    <div class="ticket-note-dialog-panel">
                        <header class="ticket-note-dialog-header">
                            <div><span class="ticket-note-dialog-kicker">Private staff note</span><h2 id="ticket-note-title-{{ $message->getKey() }}">Note from {{ $message->author?->name ?? 'Former user' }}</h2><p>{{ $message->created_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('D, d M Y · h:i A') }} {{ config('ticketing.display_timezone', 'Asia/Dhaka') }} time</p></div>
                            <button type="button" class="ticket-note-dialog-close" data-note-dialog-close aria-label="Close note">×</button>
                        </header>
                        <div class="ticket-note-dialog-body">
                            <div class="ticket-note-full-text">{{ $message->body }}</div>
                            @if($message->trashed() || !$message->is_active)
                                <div class="ticket-note-state">This note is {{ $message->trashed() ? 'archived' : 'hidden' }}.</div>
                            @endif

                            @if($message->attachments->isNotEmpty())
                                <div class="ticket-note-files"><h3>Attachments</h3>
                                    @foreach($message->attachments as $attachment)
                                        @include('admin.tickets.partials.conversation-attachment')
                                    @endforeach
                                </div>
                            @endif

                            @if($isAdmin && auth()->user()->can('messages.moderate'))
                                <form method="POST" action="{{ route('dashboard.tickets.messages.moderate', [$ticket, $message]) }}" class="ticket-moderation-form ticket-message-moderation" data-axios-form="moderation">@csrf<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="expected_updated_at" value="{{ $message->updated_at?->format('Y-m-d\TH:i:s.uP') }}"><input type="hidden" name="note_message_id" value="{{ $message->getKey() }}"><select name="action" class="form-input" required>@if($message->trashed())<option value="restore">Restore</option>@elseif($message->is_active)<option value="hide">Hide from customer</option><option value="archive">Archive</option>@else<option value="unhide">Unhide</option><option value="archive">Archive</option>@endif</select><input name="reason" class="form-input" required minlength="5" maxlength="500" placeholder="Reason for moderation"><button class="btn btn-secondary" type="submit">Moderate note</button></form>
                            @endif
                        </div>
                    </div>
                </dialog>
            @else
                <article class="ticket-message ticket-message-public {{ (int) $message->author_id === (int) $ticket->customer_id ? 'ticket-message-from-customer' : 'ticket-message-from-staff' }} {{ (int) $message->author_id === (int) auth()->id() ? 'ticket-message-own' : '' }}" data-message-id="{{ $message->getKey() }}">
                    <div class="ticket-message-avatar" aria-hidden="true">{{ str($message->author?->name ?? 'U')->substr(0, 1)->upper() }}</div>
                    <div class="ticket-message-content">
                        <header class="ticket-message-header">
                            <div class="ticket-message-author"><strong>{{ $message->author?->name ?? 'Former user' }}</strong><span>{{ $message->created_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M Y, h:i A') }} {{ config('ticketing.display_timezone', 'Asia/Dhaka') }} time</span></div>
                            <div class="ticket-message-tags"><span class="ticket-message-tag ticket-message-tag-public">{{ (int) $message->author_id === (int) $ticket->customer_id ? 'Customer' : 'Support team' }}</span>@if($message->trashed())<span class="ticket-message-tag ticket-message-tag-muted">Archived</span>@elseif(!$message->is_active)<span class="ticket-message-tag ticket-message-tag-muted">Hidden</span>@endif</div>
                        </header>

                        @if(!$message->is_active && in_array('customer', auth()->user()->tyroRoleSlugs(), true))
                            <p class="ticket-message-removed">This public message was removed by an administrator.</p>
                        @else
                            <div class="ticket-message-body">{{ $message->body }}</div>
                        @endif

                        @if($message->is_active || !in_array('customer', auth()->user()->tyroRoleSlugs(), true))
                            @foreach($message->attachments as $attachment)
                                @include('admin.tickets.partials.conversation-attachment')
                            @endforeach
                        @endif

                        @if($isAdmin && auth()->user()->can('messages.moderate'))
                            <form method="POST" action="{{ route('dashboard.tickets.messages.moderate', [$ticket, $message]) }}" class="ticket-moderation-form ticket-message-moderation" data-axios-form="moderation">@csrf<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="expected_updated_at" value="{{ $message->updated_at?->format('Y-m-d\TH:i:s.uP') }}"><select name="action" class="form-input" required>@if($message->trashed())<option value="restore">Restore</option>@elseif($message->is_active)<option value="hide">{{ (int) $message->author_id === (int) $ticket->customer_id ? 'Hide customer reply' : 'Hide from customer' }}</option><option value="archive">Archive</option>@else<option value="unhide">{{ (int) $message->author_id === (int) $ticket->customer_id ? 'Show customer reply' : 'Unhide' }}</option><option value="archive">Archive</option>@endif</select><input name="reason" class="form-input" required minlength="5" maxlength="500" placeholder="Reason for moderation"><button class="btn btn-secondary" type="submit">Moderate message</button></form>
                        @endif
                    </div>
                </article>
            @endif
        @endforeach
    </div>
</section>

@if($mayReply)
    <section class="card ticket-panel ticket-composer ticket-chat-reply">
        <div class="ticket-panel-body">
            @if($ticket->status->value === 'waiting_customer' && in_array('customer', auth()->user()->tyroRoleSlugs(), true))
                <p class="ticket-composer-note">Sending a reply resumes the ticket and restarts its SLA clock.</p>
            @elseif($ticket->status->value === 'resolved')
                <p class="ticket-composer-note">A public reply reopens the ticket. Include a reason so the work history stays clear.</p>
            @endif
            <form method="POST" enctype="multipart/form-data" action="{{ route('dashboard.tickets.messages.store', $ticket) }}" class="ticket-composer-form" data-axios-form="reply">
                @csrf<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="lock_version" value="{{ $ticket->lock_version }}"><input type="hidden" name="visibility" value="public">
                <label class="sr-only" for="public-reply">Write a reply to the customer</label><textarea id="public-reply" name="body" rows="4" minlength="5" maxlength="20000" required class="form-input ticket-chat-textarea" placeholder="Write a reply…">{{ old('body') }}</textarea>
                @if($ticket->status->value === 'resolved')<label class="form-label" for="reopen-reason">Reason for reopening</label><textarea id="reopen-reason" name="reason" rows="2" minlength="5" maxlength="500" required class="form-input">{{ old('reason') }}</textarea><label class="ticket-reopen-check"><input type="checkbox" name="reopen" value="1" required> Reopen this ticket with my reply</label>  @endif
                <div class="ticket-chat-attachment-row"><label class="ticket-chat-attachment-label" for="reply-attachments"><span aria-hidden="true">＋</span> Attach files</label><input id="reply-attachments" name="attachments[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.txt,.csv" class="form-input ticket-chat-file-input"></div><p class="ticket-upload-hint">Up to 5 private files, 10 MiB each. PDF, JPEG, PNG, TXT, or CSV.</p>
                <div class="ticket-composer-footer"><span class="ticket-composer-private"><span aria-hidden="true">◉</span> Only the customer and support team can see this reply</span><button class="btn btn-primary" type="submit"><span aria-hidden="true">➤</span> Send reply</button></div>
            </form>
        </div>
    </section>
@endif
</div>

@if($mayAddInternalNote)
    <details class="card ticket-panel ticket-composer ticket-internal-composer" {{ old('visibility') === 'internal' && $errors->any() ? 'open' : '' }}>
        <summary class="ticket-internal-composer-summary"><span class="ticket-composer-icon" aria-hidden="true">⌑</span><span><strong>Add internal note</strong><small>Private to the support team</small></span><span class="ticket-internal-composer-chevron" aria-hidden="true">⌄</span></summary>
        <div class="ticket-panel-body">
            <form method="POST" enctype="multipart/form-data" action="{{ route('dashboard.tickets.messages.store', $ticket) }}" class="ticket-composer-form">
                @csrf<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="lock_version" value="{{ $ticket->lock_version }}"><input type="hidden" name="visibility" value="internal">
                <label class="form-label" for="internal-note">Note</label><textarea id="internal-note" name="body" rows="4" minlength="5" maxlength="20000" required class="form-input">{{ old('body') }}</textarea>
                <label class="form-label" for="note-attachments">Attachments <span class="ticket-optional">Optional</span></label><input id="note-attachments" name="attachments[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.txt,.csv" class="form-input"><p class="ticket-upload-hint">Up to 5 private files, 10 MiB each. PDF, JPEG, PNG, TXT, or CSV.</p>
                <div class="ticket-composer-footer"><span class="ticket-composer-private"><span aria-hidden="true">▣</span> Internal to support team</span><button class="btn btn-secondary" type="submit">Add internal note</button></div>
            </form>
        </div>
    </details>
@endif

@push('scripts')
@vite('resources/js/ticket-operations.ts')
<script>
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const openButton = target.closest('[data-note-dialog]');
        if (openButton instanceof HTMLButtonElement) {
            document.getElementById(openButton.dataset.noteDialog)?.showModal();
            return;
        }

        const closeButton = target.closest('[data-note-dialog-close]');
        if (closeButton instanceof HTMLButtonElement) {
            closeButton.closest('dialog')?.close();
        }
    });

    document.querySelectorAll('.ticket-note-dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
    });

    document.querySelectorAll('.ticket-note-dialog[data-open-on-load]').forEach((dialog) => {
        if (dialog instanceof HTMLDialogElement) dialog.showModal();
    });
</script>
@endpush
