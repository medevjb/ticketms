<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'My support') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/css/customer-portal.css'])
    @stack('styles')
</head>
<body class="customer-app" data-realtime-user-id="{{ auth()->id() }}">
    <header class="customer-topbar">
        <div class="customer-topbar-inner">
            <a class="customer-brand" href="{{ route('customer.dashboard') }}">
                <span class="customer-brand-mark" aria-hidden="true">✳</span>
                <span>{{ config('app.name') }} <span style="font-weight:500;color:#64748b">Support</span></span>
            </a>
            <nav class="customer-nav" aria-label="Customer navigation">
                <div class="customer-nav-links" role="group" aria-label="Your support">
                    <a href="{{ route('customer.dashboard') }}" aria-label="Overview" title="Overview" class="{{ request()->routeIs('customer.dashboard') ? 'is-active' : '' }}"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="17" height="17"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V10Z" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Overview</span></a>
                    <a href="{{ route('customer.tickets.index') }}" aria-label="My requests" title="My requests" class="{{ request()->routeIs('customer.tickets.*') ? 'is-active' : '' }}"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="17" height="17"><path d="M5 5h14v14H5zM8 9h8M8 13h5" stroke-linecap="round" stroke-linejoin="round"/></svg><span>My requests</span></a>
                    <a href="{{ route('customer.notifications.index') }}" aria-label="Notifications" title="Notifications" class="{{ request()->routeIs('customer.notifications.*') ? 'is-active' : '' }}"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="17" height="17"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12h4" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Updates</span>@if(($customerUnreadCount ?? 0) > 0)<span class="customer-badge customer-badge-open">{{ $customerUnreadCount }}</span>@endif</a>
                </div>
                <div class="customer-nav-actions">
                    <a href="{{ route('customer.tickets.create') }}" title="Create a new ticket" class="customer-nav-cta"><span aria-hidden="true">＋</span><span>New ticket</span></a>
                    <details class="customer-account-menu" data-account-menu>
                        <summary class="customer-account-trigger" aria-label="Open account menu" title="{{ auth()->user()->name }}">
                            <span class="customer-nav-avatar">@if(auth()->user()->avatar_path)<img src="{{ route('customer.profile.avatar') }}" alt="">@else{{ str(auth()->user()->name)->substr(0, 1)->upper() }}@endif</span>
                            <svg class="customer-account-chevron" aria-hidden="true" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.22 7.47a.75.75 0 0 1 1.06 0L10 11.19l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
                        </summary>
                        <div class="customer-account-dropdown">
                            <div class="customer-account-identity"><strong>{{ auth()->user()->name }}</strong><span>{{ auth()->user()->email }}</span></div>
                            <a href="{{ route('customer.profile') }}" class="customer-account-link">Profile &amp; settings</a>
                            <form method="POST" action="{{ route('tyro-login.logout') }}">@csrf<button type="submit" class="customer-account-signout">Sign out</button></form>
                        </div>
                    </details>
                </div>
            </nav>
        </div>
    </header>
    <main class="customer-main">
        @yield('content')
    </main>
    <div class="customer-realtime-announcement" data-live-announcement role="status" aria-live="polite"></div>
    <footer class="customer-footer"><div class="customer-footer-inner"><span>© {{ now()->year }} {{ config('app.name') }} · Support that keeps you moving.</span><a href="{{ route('home') }}">Support home</a></div></footer>
    @stack('scripts')
    @vite('resources/js/customer-realtime.ts')
    <script>
        document.addEventListener('click', (event) => {
            document.querySelectorAll('[data-account-menu][open]').forEach((menu) => {
                if (!menu.contains(event.target)) menu.removeAttribute('open');
            });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') document.querySelectorAll('[data-account-menu][open]').forEach((menu) => {
                menu.removeAttribute('open');
                menu.querySelector('summary')?.focus();
            });
        });
    </script>
    @php
        $toastMessages = [];
        foreach (['success', 'error', 'warning', 'info'] as $type) {
            if (session($type)) {
                $toastMessages[] = session($type);
            }
        }
        $toastMessages = array_values(array_unique(array_merge($toastMessages, $errors->all())));
        $hasToastError = $errors->any() || session('error');
    @endphp
    @if($toastMessages !== [])
        <div class="customer-toast {{ $hasToastError ? 'customer-toast-error' : '' }}" role="{{ $hasToastError ? 'alert' : 'status' }}" aria-live="{{ $hasToastError ? 'assertive' : 'polite' }}" id="customer-toast">
            <div>@foreach($toastMessages as $message)<p style="margin:0">{{ $message }}</p>@endforeach</div>
            <button type="button" aria-label="Dismiss notification" onclick="document.getElementById('customer-toast').remove()">×</button>
        </div>
        <script>window.setTimeout(() => document.getElementById('customer-toast')?.remove(), 6500);</script>
    @endif
@include('admin.confirmation-dialog')
</body>
</html>
