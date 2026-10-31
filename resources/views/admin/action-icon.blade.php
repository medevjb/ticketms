@pushOnce('styles', 'admin-action-icons')
<style>
    .btn.admin-icon-button { width:36px; height:36px; min-width:36px; padding:0; flex-shrink:0; vertical-align:middle; }
    .btn.admin-icon-button:focus-visible { outline:2px solid var(--primary); outline-offset:3px; }
    .admin-icon-button svg { width:18px; height:18px; }
    .admin-row-actions { display:flex; align-items:center; justify-content:flex-end; gap:8px; }
    .admin-row-actions form { display:flex; margin:0; }
</style>
@endPushOnce
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($icon)
        @case('edit')
            <path d="m16 3 5 5M4 20l4-1L21 6a2.1 2.1 0 0 0-3-3L5 16l-1 4Z"/><path d="M13 4H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h13a2 2 0 0 0 2-2v-8"/>
            @break
        @case('view')
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>
            @break
        @case('archive')
            <rect x="3" y="3" width="18" height="4" rx="1"/><path d="M5 7v13h14V7M10 11h4"/>
            @break
        @case('refresh')
            <path d="M20 7v5h-5M4 17v-5h5M6.1 7a7 7 0 0 1 11.6-2L20 8M4 16l2.3 3A7 7 0 0 0 18 17"/>
            @break
        @case('restore')
            <path d="M3 11a9 9 0 1 1 2.7 7M3 4v7h7M12 8v5l3 2"/>
            @break
        @case('publish')
            <path d="m5 12 4 4L19 6M4 21h16"/>
            @break
        @case('availability')
            <path d="M12 2v10M5.6 5.6a9 9 0 1 0 12.8 0"/>
            @break
    @endswitch
</svg>
