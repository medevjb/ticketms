@pushOnce('styles', 'conversation-initial-issue')
<style>
    .conversation-image-card { width:160px; max-width:100%; margin:12px 0 0; border:1px solid var(--border,#dbe3ec); border-radius:10px; overflow:hidden; background:var(--card,#fff); }
    .conversation-image-preview { display:block; position:relative; width:100%; overflow:hidden; background:var(--muted,#f1f5f9); }
    .conversation-image-preview img { display:block; width:100%; height:96px; object-fit:cover; }
    .conversation-image-preview span { position:absolute; right:6px; bottom:6px; padding:3px 5px; border-radius:5px; background:#0f172acc; color:#fff; font-size:9px; font-weight:600; }
    .conversation-image-footer { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:9px; }
    .conversation-image-meta { min-width:0; display:grid; gap:3px; }
    .conversation-image-meta strong { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:11px; font-weight:600; color:var(--foreground,#334155); }
    .conversation-image-meta small { font-size:10px; color:var(--muted-foreground,#64748b); }
    .conversation-image-download { width:28px; height:28px; display:inline-flex; align-items:center; justify-content:center; flex:none; border-radius:6px; background:var(--muted,#f1f5f9); color:var(--foreground,#334155); }
    .conversation-image-download svg { width:15px; height:15px; }
    .conversation-image-card a:focus-visible { outline:2px solid var(--info,#0284c7); outline-offset:-2px; }
</style>
<style>
    .conversation-original-request { overflow:hidden; border:1px solid color-mix(in srgb,var(--info,#0284c7) 22%,var(--border,#dbe3ec)); border-radius:12px; background:var(--card,#fff); }
    .conversation-original-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 18px; border-bottom:1px solid var(--border,#e2e8f0); background:color-mix(in srgb,var(--info,#0284c7) 6%,var(--card,#fff)); }
    .conversation-original-title { display:flex; align-items:center; gap:9px; min-width:0; }
    .conversation-original-title svg { width:18px; height:18px; flex:none; color:var(--info,#0284c7); }
    .conversation-original-title h3 { margin:0; font-size:13px; font-weight:700; color:var(--foreground,#0f172a); }
    .conversation-original-label { color:var(--muted-foreground,#64748b); font-size:10px; font-weight:600; white-space:nowrap; }
    .conversation-original-content { padding:16px 18px 18px; }
    .conversation-original-meta { display:flex; align-items:center; gap:6px 14px; flex-wrap:wrap; margin-bottom:12px; color:var(--muted-foreground,#64748b); font-size:11px; }
    .conversation-original-meta strong { color:var(--foreground,#334155); font-weight:600; }
    .conversation-original-description { white-space:pre-wrap; overflow-wrap:anywhere; font-size:13px; line-height:1.8; color:var(--foreground,#334155); }
    .conversation-original-files { display:flex; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-top:14px; }
    .conversation-original-files > a { padding:8px 10px; border:1px solid var(--border,#e2e8f0); border-radius:8px; font-size:12px; overflow-wrap:anywhere; }
    .conversation-original-files .conversation-image-card { margin:0; }
    @media(max-width:560px) { .conversation-original-heading { padding:12px; } .conversation-original-content { padding:12px; } .conversation-original-label { font-size:9px; } }
</style>
@endPushOnce
<article class="conversation-original-request" aria-labelledby="initial-issue-title">
    <header class="conversation-original-heading">
        <div class="conversation-original-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8ZM14 2v6h6M8 12h8M8 16h6"/></svg><h3 id="initial-issue-title">Initial issue</h3></div>
        <span class="conversation-original-label">Original request</span>
    </header>
    <div class="conversation-original-content">
        <div class="conversation-original-meta"><span>Submitted by <strong>{{ $ticket->creator?->name ?? 'Former user' }}</strong></span><time datetime="{{ $ticket->created_at?->toIso8601String() }}">{{ $ticket->created_at?->timezone($timezone)->format('d M Y, h:i A') }} · {{ $timezone }}</time></div>
        <div class="conversation-original-description">{{ $ticket->description }}</div>
        @if($ticket->attachments->isNotEmpty())
        <div class="conversation-original-files">
            @foreach($ticket->attachments as $attachment)
                @if($attachment->is_active && !$attachment->trashed())
                    @if($attachment->isPreviewableImage())
                        @include('admin.tickets.partials.attachment-preview', ['previewRoute'=>$attachmentPreviewRoute])
                    @else
                        <a href="{{ route($attachmentDownloadRoute, $attachment) }}">{{ $attachment->original_name }} · {{ number_format($attachment->size_bytes / 1024) }} KiB</a>
                    @endif
                @endif
            @endforeach
        </div>
        @endif
    </div>
</article>
