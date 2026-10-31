@if($attachment->isPreviewableImage() && $attachment->is_active && !$attachment->trashed())
<figure class="conversation-image-card">
    <a class="conversation-image-preview" href="{{ route($previewRoute, $attachment) }}" target="_blank" rel="noopener noreferrer" aria-label="Open image {{ $attachment->original_name }}">
        <img src="{{ route($previewRoute, $attachment) }}" alt="{{ $attachment->original_name }}" loading="lazy" decoding="async">
        <span aria-hidden="true">Open image ↗</span>
    </a>
    <figcaption class="conversation-image-footer">
        <div class="conversation-image-meta"><strong title="{{ $attachment->original_name }}">{{ $attachment->original_name }}</strong><small>{{ number_format($attachment->size_bytes / 1024, 0) }} KiB</small></div>
        <a class="conversation-image-download" href="{{ route(str_replace('.preview', '.download', $previewRoute), $attachment) }}" title="Download {{ $attachment->original_name }}" aria-label="Download {{ $attachment->original_name }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m-4-4 4 4 4-4M4 16v5h16v-5"/></svg></a>
    </figcaption>
</figure>
@endif
