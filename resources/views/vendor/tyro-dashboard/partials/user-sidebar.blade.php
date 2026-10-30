<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="{{ route($dashboardRoute::name('index')) }}" class="sidebar-logo">
            @php
                $sidebarLogo = config('tyro-dashboard.branding.sidebar_logo');
                $sidebarLogoSrc = $sidebarLogo && !str_starts_with($sidebarLogo, 'http://') && !str_starts_with($sidebarLogo, 'https://')
                    ? \Illuminate\Support\Facades\Storage::url($sidebarLogo)
                    : $sidebarLogo;
            @endphp
            @if($sidebarLogo)
                <img src="{{ $sidebarLogoSrc }}" alt="{{ $branding['app_name'] ?? config('app.name', 'Laravel') }}" class="sidebar-logo-img">
            @else
                <div class="sidebar-logo-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
            @endif
            <span class="sidebar-logo-text">{{ $branding['app_name'] ?? config('app.name', 'Laravel') }}</span>
        </a>
        @if(config('tyro-dashboard.collapsible_sidebar', false))
            <button class="sidebar-collapse-btn" onclick="toggleSidebarCollapse()" aria-label="Collapse sidebar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>
        @endif
    </div>
    @if(config('tyro-dashboard.collapsible_sidebar', false))
        <button class="sidebar-expand-btn" onclick="toggleSidebarCollapse()" aria-label="Expand sidebar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
        </button>
    @endif

    <nav class="sidebar-nav sidebar-accordion"
        data-sidebar-accordion
        data-sidebar-accordion-compact="{{ config('tyro-dashboard.branding.sidebar_accordion_compact', false) ? 'true' : 'false' }}"
        data-sidebar-accordion-open-sections="{{ config('tyro-dashboard.branding.sidebar_accordion_open_sections', 1) }}">
        <div class="sidebar-section">
            <div class="sidebar-section-title">Menu</div>
            <a href="{{ route($dashboardRoute::name('index')) }}" class="sidebar-link {{ request()->routeIs($dashboardRoute::pattern('index')) ? 'active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                Dashboard
            </a>
            <a href="{{ route($dashboardRoute::name('profile')) }}" class="sidebar-link {{ request()->routeIs($dashboardRoute::pattern('profile*')) ? 'active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                My Profile
            </a>
            @if(auth()->user() && auth()->user()->can('tickets.view'))
                <a href="{{ route('dashboard.tickets.index') }}" class="sidebar-link {{ request()->routeIs('dashboard.tickets.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 5h16v14H4zM8 9h8M8 13h5" />
                    </svg>
                    {{ in_array('agent', auth()->user()->tyroRoleSlugs(), true) ? 'Assigned tickets' : 'My tickets' }}
                </a>
            @endif
            @if(auth()->user() && auth()->user()->can('analytics.view'))
                <a href="{{ route('dashboard.analytics.index') }}" class="sidebar-link {{ request()->routeIs('dashboard.analytics.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 19V5m0 14h16M8 15v-4m4 4V8m4 7v-6" />
                    </svg>
                    {{ in_array('agent', auth()->user()->tyroRoleSlugs(), true) ? 'My performance' : 'My activity' }}
                </a>
            @endif
            @if(auth()->user() && auth()->user()->can('notifications.view'))
                <a href="{{ route('dashboard.notifications.index') }}" class="sidebar-link {{ request()->routeIs('dashboard.notifications.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-4-5.7V5a2 2 0 10-4 0v.3A6 6 0 006 11v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 11-6 0m6 0H9" />
                    </svg>
                    Notifications
                </a>
            @endif

            @foreach($commonMenuItems ?? [] as $item)
                <a href="{{ route($item['route'] ?? '#') }}" class="sidebar-link {{ request()->routeIs($item['route'] ?? '') ? 'active' : '' }}">
                    @if(isset($item['icon']))
                        {!! $item['icon'] !!}
                    @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    @endif
                    {{ $item['title'] ?? 'Menu Item' }}
                </a>
            @endforeach

            @foreach($userMenuItems ?? [] as $item)
                <a href="{{ route($item['route'] ?? '#') }}" class="sidebar-link {{ request()->routeIs($item['route'] ?? '') ? 'active' : '' }}">
                    @if(isset($item['icon']))
                        {!! $item['icon'] !!}
                    @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    @endif
                    {{ $item['title'] ?? 'Menu Item' }}
                </a>
            @endforeach
        </div>
    </nav>
</aside>
