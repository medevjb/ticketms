import { sla as customerTicketSla } from '@/actions/App/Http/Controllers/Customer/TicketController';
import { sla as ticketSla } from '@/actions/App/Http/Controllers/Admin/TicketController';

type Countdown = {
    state: 'within' | 'approaching' | 'breached' | 'paused' | 'stopped';
    remaining_seconds: number;
    server_time: number;
    counting_until: number | null;
};

const panel = document.querySelector<HTMLElement>('[data-sla-countdown]');
if (panel) {
    let snapshot = JSON.parse(panel.dataset.slaCountdown ?? '{}') as Countdown;
    let syncedAt = performance.now();
    let warned = false;
    const clock = panel.querySelector<HTMLElement>('[data-sla-clock]');
    const note = panel.querySelector<HTMLElement>('[data-sla-clock-note]');
    const status = panel.querySelector<HTMLElement>('[data-sla-status]');
    const stateLabels = {
        within: 'On track',
        approaching: 'Approaching target',
        breached: 'Target breached',
        paused: 'Paused',
        stopped: 'Stopped',
    };

    const render = () => {
        const elapsed = Math.max(
            0,
            Math.floor((performance.now() - syncedAt) / 1000),
        );
        const windowRemaining =
            snapshot.counting_until === null
                ? 0
                : Math.max(0, snapshot.counting_until - snapshot.server_time);
        const consumed = Math.min(elapsed, windowRemaining, 60);
        const remaining = Math.max(0, snapshot.remaining_seconds - consumed);
        const state =
            remaining === 0 && !['paused', 'stopped'].includes(snapshot.state)
                ? 'breached'
                : snapshot.state;
        if (clock) {
            clock.textContent = `${String(Math.floor(remaining / 3600)).padStart(2, '0')}:${String(Math.floor((remaining % 3600) / 60)).padStart(2, '0')}:${String(remaining % 60).padStart(2, '0')}`;
        }
        if (status) status.textContent = stateLabels[state];
        const stateElement = panel.querySelector('.ticket-sla-state');
        if (stateElement)
            stateElement.className = `ticket-sidebar-value ticket-sla-state ticket-sla-${state}`;
        if (note)
            note.textContent =
                elapsed > 60
                    ? 'Waiting for the next live update'
                    : state === 'paused'
                      ? 'Paused while waiting for the customer'
                      : state === 'stopped'
                        ? 'Timer stopped'
                        : state === 'breached'
                          ? 'SLA target breached'
                          : consumed < windowRemaining
                            ? 'Business time remaining · live'
                            : 'Outside business hours';
    };
    const sync = async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(
                (panel.dataset.slaAudience === 'customer'
                    ? customerTicketSla
                    : ticketSla
                ).url(Number(panel.dataset.slaTicket)),
                {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                },
            );
            if (!response.ok)
                throw new Error('SLA countdown could not be refreshed.');
            snapshot = (await response.json()) as Countdown;
            syncedAt = performance.now();
            warned = false;
            render();
        } catch {
            if (!warned)
                window.showToast?.(
                    'SLA live updates are unavailable. The timer will resync when the connection returns.',
                    'error',
                );
            warned = true;
        }
    };
    render();
    const tick = window.setInterval(render, 1000);
    const poll = window.setInterval(() => void sync(), 30000);
    document.addEventListener('customer:ticket-updated', () => {
        void sync();
    });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) void sync();
    });
    window.addEventListener(
        'pagehide',
        () => {
            clearInterval(tick);
            clearInterval(poll);
        },
        { once: true },
    );
}
