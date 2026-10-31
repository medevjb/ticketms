import { echo } from './echo';

type RealtimeAttachment = {
    id: number;
    name: string;
    size_bytes: number;
    is_image: boolean;
};

type TicketRealtimeUpdate = {
    action: string;
    ticket: {
        id: number;
        number: string;
        subject: string;
        status: string;
        priority: string | null;
        lock_version: number;
        updated_at: string | null;
    };
    message: {
        id: number;
        body: string;
        author_id: number;
        author: string;
        created_at: string | null;
        attachments: RealtimeAttachment[];
    } | null;
};

const userId = document.body.dataset.realtimeUserId;
if (userId) {
    echo.private(`user.${userId}`).listen('.ticket.updated', (update: TicketRealtimeUpdate) => {
        applyUpdate(update);
    });
}

function applyUpdate(update: TicketRealtimeUpdate): void {
    const ticketId = String(update.ticket.id);
    const ticketView = document.querySelector<HTMLElement>('[data-customer-ticket-id]');
    const viewingThisTicket = ticketView?.dataset.customerTicketId === ticketId;

    if (viewingThisTicket && ticketView) {
        const previousStatus = ticketView.dataset.ticketStatus;
        updateTicketDetails(ticketView, update.ticket);
        if (update.message) {
            appendMessage(ticketView, update.message);
        }
        document.dispatchEvent(new CustomEvent('customer:ticket-updated', { detail: update.ticket }));
        if (previousStatus && previousStatus !== update.ticket.status) {
            window.location.reload();
            return;
        }
    }

    const row = document.querySelector<HTMLElement>(`[data-ticket-row="${ticketId}"]`);
    if (row) {
        updateTicketRow(row, update.ticket);
    } else if (update.action === 'ticket.created' && document.querySelector('[data-ticket-list]')) {
        window.location.reload();
        return;
    }

    announceUpdate(`${update.ticket.number} was updated.`);
}

function updateTicketDetails(container: HTMLElement, ticket: TicketRealtimeUpdate['ticket']): void {
    container.dataset.ticketStatus = ticket.status;
    container.querySelectorAll<HTMLElement>('.customer-badge').forEach((badge) => {
        if (badge.classList.contains('customer-badge-open')
            || badge.classList.contains('customer-badge-in_progress')
            || badge.classList.contains('customer-badge-waiting_customer')
            || badge.classList.contains('customer-badge-resolved')
            || badge.classList.contains('customer-badge-closed')) {
            badge.className = `customer-badge customer-badge-${ticket.status}`;
            badge.textContent = statusLabel(ticket.status);
        }
    });

    container.querySelectorAll<HTMLInputElement>('input[name="lock_version"]').forEach((input) => {
        input.value = String(ticket.lock_version);
    });

    const priority = container.querySelector<HTMLElement>('[data-ticket-priority]');
    if (priority && ticket.priority) {
        priority.textContent = ticket.priority;
    }
}

function updateTicketRow(row: HTMLElement, ticket: TicketRealtimeUpdate['ticket']): void {
    const status = row.querySelector<HTMLElement>('[data-ticket-status-badge]');
    if (status) {
        status.className = `customer-badge customer-badge-${ticket.status}`;
        status.textContent = statusLabel(ticket.status);
    }

    const priority = row.querySelector<HTMLElement>('[data-ticket-priority]');
    if (priority && ticket.priority) {
        priority.textContent = ticket.priority;
    }

    const updatedAt = row.querySelector<HTMLElement>('[data-ticket-updated-at]');
    if (updatedAt && ticket.updated_at) {
        updatedAt.textContent = new Intl.DateTimeFormat(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: 'Asia/Dhaka',
        }).format(new Date(ticket.updated_at));
    }
}

function appendMessage(
    ticketView: HTMLElement,
    message: NonNullable<TicketRealtimeUpdate['message']>,
): void {
    const conversation = ticketView.querySelector<HTMLElement>('.customer-conversation-body');
    if (!conversation || conversation.querySelector(`[data-message-id="${message.id}"]`)) {
        return;
    }

    const isCustomerMessage = message.author_id === Number(ticketView.dataset.customerId);
    const article = document.createElement('article');
    article.className = `customer-chat-message ${isCustomerMessage ? 'customer-chat-message-self' : 'customer-chat-message-support'}`;
    article.dataset.messageId = String(message.id);

    const avatar = document.createElement('span');
    avatar.className = 'customer-chat-avatar';
    avatar.setAttribute('aria-hidden', 'true');
    avatar.textContent = Array.from(message.author)[0]?.toUpperCase() ?? 'S';

    const content = document.createElement('div');
    content.className = 'customer-chat-content';
    const meta = document.createElement('header');
    meta.className = 'customer-chat-meta';
    const author = document.createElement('div');
    author.className = 'customer-chat-author';
    const authorName = document.createElement('strong');
    authorName.textContent = message.author;
    const time = document.createElement('time');
    if (message.created_at) {
        time.dateTime = message.created_at;
        time.textContent = `${new Intl.DateTimeFormat(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: 'Asia/Dhaka',
        }).format(new Date(message.created_at))} Dhaka time`;
    }
    author.append(authorName, time);
    const role = document.createElement('span');
    role.className = 'customer-chat-role';
    role.textContent = isCustomerMessage ? 'You' : 'Support team';
    meta.append(author, role);

    const bubble = document.createElement('p');
    bubble.className = 'customer-chat-bubble';
    bubble.textContent = message.body;
    content.append(meta, bubble);

    for (const attachment of message.attachments) {
        content.append(createAttachment(attachment));
    }

    article.append(avatar, content);
    conversation.append(article);
    conversation.scrollTop = conversation.scrollHeight;

    const count = ticketView.querySelector<HTMLElement>('.customer-conversation-count');
    if (count) {
        const messageCount = conversation.querySelectorAll('[data-message-id]').length;
        count.textContent = `${messageCount} ${messageCount === 1 ? 'message' : 'messages'}`;
    }
}

function createAttachment(attachment: RealtimeAttachment): HTMLElement {
    const card = document.createElement('div');
    card.className = 'customer-chat-attachment';

    if (attachment.is_image) {
        const preview = document.createElement('a');
        preview.href = `/portal/ticket-attachments/${attachment.id}/preview`;
        preview.target = '_blank';
        preview.rel = 'noopener';
        preview.setAttribute('aria-label', `Open image ${attachment.name}`);
        const image = document.createElement('img');
        image.src = preview.href;
        image.alt = attachment.name;
        image.loading = 'lazy';
        preview.append(image);
        card.append(preview);
    }

    const details = document.createElement('div');
    details.className = 'customer-chat-attachment-details';
    const name = document.createElement('span');
    name.textContent = `${attachment.name} · ${Math.ceil(attachment.size_bytes / 1024)} KiB`;
    const download = document.createElement('a');
    download.className = 'customer-link';
    download.href = `/portal/ticket-attachments/${attachment.id}/download`;
    download.textContent = 'Download';
    details.append(name, download);
    card.append(details);

    return card;
}

function statusLabel(status: string): string {
    return ({
        open: 'Open',
        in_progress: 'In progress',
        waiting_customer: 'Waiting for you',
        resolved: 'Resolved',
        closed: 'Closed',
    } as Record<string, string>)[status] ?? status;
}

function announceUpdate(message: string): void {
    const announcement = document.querySelector<HTMLElement>('[data-live-announcement]');
    if (!announcement) {
        return;
    }

    announcement.textContent = message;
    window.setTimeout(() => {
        if (announcement.textContent === message) {
            announcement.textContent = '';
        }
    }, 5000);
}
