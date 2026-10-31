import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const csrfToken =
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';
const host = import.meta.env.VITE_REVERB_HOST ?? window.location.hostname;
const port = Number(
    import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 8080),
);

export const echo = new Echo<'reverb'>({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? '',
    Pusher,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {},
    },
});
