@php
    $toastPosition = config('tyro-dashboard.notifications.toast_position', 'bottom-right');
    $autoDismissSeconds = config('tyro-dashboard.notifications.auto_dismiss_seconds', 5);
    $notifications = [];
    foreach (['success', 'error', 'warning', 'info'] as $type) {
        if (session($type)) {
            $notifications[] = ['type' => $type, 'messages' => [session($type)]];
        }
    }
    if ($errors->any()) {
        $notifications[] = ['type' => 'error', 'messages' => array_values(array_unique($errors->all()))];
    }
    if (session('bulk_failures')) {
        $notifications[] = [
            'type' => 'warning',
            'title' => 'Some selected records could not be updated.',
            'messages' => array_map(fn ($failure) => 'Record #'.$failure['id'].': '.$failure['message'], session('bulk_failures')),
        ];
    }
@endphp
<div id="toast-container" class="toast-container" data-position="{{ $toastPosition }}" data-auto-dismiss="{{ $autoDismissSeconds * 1000 }}">
    @foreach($notifications as $notification)
    <div class="toast toast-{{ $notification['type'] }}" role="{{ $notification['type'] === 'error' ? 'alert' : 'status' }}" aria-live="{{ $notification['type'] === 'error' ? 'assertive' : 'polite' }}">
        <div class="toast-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/>
                @if($notification['type'] === 'success')<path stroke-linecap="round" stroke-linejoin="round" d="m8 12 3 3 5-6"/>
                @else<path stroke-linecap="round" d="M12 7v6m0 4h.01"/>@endif
            </svg>
        </div>
        <div class="toast-content">
            @isset($notification['title'])<p class="toast-title">{{ $notification['title'] }}</p>@endisset
            @if(count($notification['messages']) === 1)
                <p class="toast-message">{{ $notification['messages'][0] }}</p>
            @else
                <ul class="toast-error-list">@foreach($notification['messages'] as $message)<li>{{ $message }}</li>@endforeach</ul>
            @endif
        </div>
        <button class="toast-close" type="button" aria-label="Dismiss notification" onclick="dismissToast(this.closest('.toast'))">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="m6 6 12 12M6 18 18 6"/></svg>
        </button>
    </div>
    @endforeach
</div>
