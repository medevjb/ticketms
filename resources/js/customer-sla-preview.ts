import { slaPreview } from '@/actions/App/Http/Controllers/Customer/TicketController';

type SlaOverview = {
    timezone: string;
    targets: {
        priority_id: number;
        priority: string;
        duration: string;
        due_label: string | null;
    }[];
    availability: {
        is_open: boolean;
        message: string;
        timezone: string;
        next_open_label: string | null;
    };
};

const root = document.querySelector<HTMLElement>('[data-customer-sla-create]');
const priority = document.querySelector<HTMLSelectElement>('#priority_id');
if (root && priority) {
    let overview = JSON.parse(
        root.dataset.slaInitial ?? 'null',
    ) as SlaOverview | null;
    const render = () => {
        const target = overview?.targets.find(
            (candidate) =>
                Number(candidate.priority_id) === Number(priority.value),
        );
        const estimate = target?.due_label
            ? `${target.due_label} · ${overview?.timezone}`
            : priority.value
              ? 'Your resolution target will be confirmed after submission'
              : 'Choose a priority to see your expected target';
        const clock = document.querySelector<HTMLElement>(
            '[data-sla-estimated-time]',
        );
        const duration = document.querySelector<HTMLElement>(
            '[data-sla-estimated-duration]',
        );
        const review = document.querySelector<HTMLElement>('[data-review-sla]');
        if (clock) clock.textContent = estimate;
        if (review)
            review.textContent = target
                ? `${estimate} (${target.duration})`
                : estimate;
        if (duration)
            duration.textContent = target
                ? `${target.priority} · ${target.duration}. Non-working hours and holidays are excluded.`
                : 'Scheduled business hours and holidays are included in this estimate.';
        if (overview?.availability) {
            const status = root.querySelector<HTMLElement>(
                '[data-service-status]',
            );
            const title = root.querySelector<HTMLElement>(
                '[data-service-status-message]',
            );
            const detail = root.querySelector<HTMLElement>(
                '[data-service-status-detail]',
            );
            const availability = overview.availability;
            status?.classList.toggle('is-open', availability.is_open);
            status?.classList.toggle('is-closed', !availability.is_open);
            if (title) title.textContent = availability.message;
            if (detail)
                detail.textContent = availability.is_open
                    ? 'Requests count toward their resolution target during scheduled service hours.'
                    : availability.next_open_label
                      ? `You can submit a ticket now. Service resumes ${availability.next_open_label} · ${availability.timezone}. Non-working hours do not consume SLA time.`
                      : 'You can still submit a ticket. Your service target will be confirmed after submission.';
        }
    };
    const refresh = async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(slaPreview.url(), {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (response.ok) {
                overview = (await response.json()) as SlaOverview | null;
                render();
            }
        } catch {
            // Retain the last calendar estimate; submission calculates the authoritative target.
        }
    };
    priority.addEventListener('change', () => {
        render();
        void refresh();
    });
    render();
    const timer = window.setInterval(() => void refresh(), 60000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) void refresh();
    });
    window.addEventListener('pagehide', () => clearInterval(timer), {
        once: true,
    });
}
