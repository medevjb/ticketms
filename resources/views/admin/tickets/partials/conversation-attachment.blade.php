@php($hasPreview = $attachment->isPreviewableImage() && $attachment->is_active && !$attachment->trashed() && $message->is_active && !$message->trashed())
@if($hasPreview)
    @include('admin.tickets.partials.attachment-preview', ['previewRoute'=>'dashboard.ticket-attachments.preview'])
@else
    <div class="ticket-message-attachment">
        <span><strong>{{ $attachment->original_name }}</strong><small>{{ number_format($attachment->size_bytes / 1024, 0) }} KiB</small></span>
        @if($attachment->is_active && !$attachment->trashed() && $message->is_active && !$message->trashed())<a href="{{ route('dashboard.ticket-attachments.download', $attachment) }}">Download</a>@else<span class="ticket-message-attachment-unavailable">Unavailable</span>@endif
    </div>
@endif
@if($isAdmin && auth()->user()->can('attachments.moderate'))
    <form method="POST" action="{{ route('dashboard.tickets.attachments.moderate', [$ticket, $attachment]) }}" class="ticket-moderation-form">
        @csrf
        <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <input type="hidden" name="expected_updated_at" value="{{ $attachment->updated_at?->format('Y-m-d\TH:i:s.uP') }}">
        @if($message->visibility->value === 'internal')<input type="hidden" name="note_message_id" value="{{ $message->getKey() }}">@endif
        <select name="action" class="form-input" required aria-label="Attachment action">@if($attachment->trashed())<option value="restore">Restore</option>@elseif($attachment->is_active)<option value="disable">Disable</option><option value="archive">Archive</option>@else<option value="enable">Enable</option><option value="archive">Archive</option>@endif</select>
        <input name="reason" class="form-input" required minlength="5" maxlength="500" placeholder="Reason for file change" aria-label="Reason for file change">
        <button class="btn btn-secondary" type="submit">Save</button>
    </form>
@endif
