import './ticket-sla-countdown';
import axios from 'axios';

type AttachmentRecord = {
    name: string;
    size: number;
    url: string;
    preview_url?: string | null;
};

type MessageRecord = {
    id: number;
    body: string;
    visibility: 'public' | 'internal';
    author_id: number;
    author: string;
    created_at: string;
    updated_at: string | null;
    is_active: boolean;
    can_moderate?: boolean;
    moderation_url?: string | null;
    attachments: AttachmentRecord[];
};

type ModerationRecord = Pick<
    MessageRecord,
    'id' | 'is_active' | 'updated_at'
> & {
    action: string;
    archived: boolean;
};

type TicketPayload = {
    lock_version: number;
    status?: string;
    priority_id?: number;
    priority?: string | null;
    assigned_agent_id?: number | null;
    assigned_agent?: string | null;
};

type AjaxResponse = {
    message: string;
    record?: MessageRecord | ModerationRecord;
    ticket?: TicketPayload;
};

declare global {
    interface Window {
        showToast?: (message: string, type?: string) => unknown;
    }
}

const csrfToken =
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';

document.querySelectorAll<HTMLFormElement>('form').forEach((form) => {
    const action = new URL(form.action, window.location.href).pathname;

    if (form.matches('.customer-form') && action.endsWith('/messages')) {
        form.dataset.axiosForm = 'reply';
        form.querySelector<HTMLInputElement>(
            '.customer-chat-file-input',
        )?.addEventListener('change', () => {
            updateAttachmentStatus(form);
        });
    } else if (action.endsWith('/priority')) {
        form.dataset.axiosForm = 'priority';
    } else if (action.endsWith('/assignment')) {
        form.dataset.axiosForm = 'assignment';
    } else if (action.endsWith('/automatic-assignment')) {
        form.dataset.axiosForm = 'agent-eligibility';
    }
});

document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.axiosForm) {
        return;
    }

    event.preventDefault();
    clearErrors(form);

    const submitButton =
        event.submitter instanceof HTMLButtonElement
            ? event.submitter
            : form.querySelector<HTMLButtonElement>('button[type="submit"]');
    const originalButtonNodes = submitButton
        ? Array.from(submitButton.childNodes, (node) => node.cloneNode(true))
        : [];
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Saving…';
    }

    try {
        const response = await axios.post<AjaxResponse>(
            form.action,
            new FormData(form),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
            },
        );

        const payload = response.data;
        if (payload.ticket?.lock_version) {
            updateLockVersions(payload.ticket.lock_version);
        }

        switch (form.dataset.axiosForm) {
            case 'reply':
                if (
                    payload.record &&
                    'body' in payload.record &&
                    payload.ticket
                ) {
                    const previousStatus = ticketRoot()?.dataset.ticketStatus;
                    appendMessage(payload.record);
                    form.reset();
                    updateAttachmentStatus(form);
                    rotateOperationKey(form);
                    updateLockVersions(payload.ticket.lock_version);
                    toast(payload.message, 'success');

                    if (
                        previousStatus &&
                        payload.ticket.status &&
                        previousStatus !== payload.ticket.status
                    ) {
                        window.setTimeout(() => window.location.reload(), 700);
                    }
                }
                break;
            case 'moderation':
                if (payload.record && 'action' in payload.record) {
                    updateModeratedMessage(form, payload.record);
                    rotateOperationKey(form);
                    toast(payload.message, 'success');
                    if (
                        payload.record.archived ||
                        payload.record.action === 'restore'
                    ) {
                        window.setTimeout(() => window.location.reload(), 700);
                    }
                }
                break;
            case 'assignment':
                if (payload.ticket) {
                    updateAssignment(payload.ticket);
                    rotateOperationKey(form);
                    toast(payload.message, 'success');
                }
                break;
            case 'priority':
                if (payload.ticket) {
                    toast(payload.message, 'success');
                    window.setTimeout(() => window.location.reload(), 700);
                }
                break;
            case 'agent-eligibility': {
                const enabledInput = form.querySelector<HTMLInputElement>(
                    'input[name="auto_assignment_enabled"]',
                );
                if (enabledInput) {
                    enabledInput.value = enabledInput.value === '1' ? '0' : '1';
                    rotateOperationKey(form);
                    toast(payload.message, 'success');
                }
                break;
            }
        }
    } catch (error: unknown) {
        const response = axios.isAxiosError<{
            errors?: Record<string, string[]>;
        }>(error)
            ? error.response
            : undefined;
        if (response?.status === 422 && response.data.errors) {
            showErrors(form, response.data.errors);
            toast(
                [...new Set(Object.values(response.data.errors).flat())].join(
                    ' ',
                ),
                'error',
            );
        } else if (response?.status === 409) {
            toast(
                form.dataset.axiosForm === 'agent-eligibility'
                    ? 'Agent eligibility changed. Reload the page and try again.'
                    : 'This ticket changed while you were working. Reload the page and try again.',
                'error',
            );
        } else if (response?.status === 403) {
            toast('You are not allowed to perform this action.', 'error');
        } else {
            toast(
                'The operation could not be completed. Please try again.',
                'error',
            );
        }
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.replaceChildren(...originalButtonNodes);
        }
        if (form.dataset.axiosForm === 'agent-eligibility') {
            syncAgentEligibilitySwitch(form);
        }
    }
});

function syncAgentEligibilitySwitch(form: HTMLFormElement): void {
    const enabledInput = form.querySelector<HTMLInputElement>(
        'input[name="auto_assignment_enabled"]',
    );
    const switchButton =
        form.querySelector<HTMLButtonElement>('[role="switch"]');
    const label = form.querySelector<HTMLElement>(
        '.agent-assignment-switch-label',
    );
    if (!enabledInput || !switchButton || !label) {
        return;
    }

    const enabled = enabledInput.value === '0';
    switchButton.setAttribute('aria-checked', String(enabled));
    switchButton.classList.toggle('is-enabled', enabled);
    label.textContent = enabled ? 'Enabled' : 'Disabled';
}

function ticketRoot(): HTMLElement | null {
    return document.querySelector<HTMLElement>('[data-ticket-status]');
}

function updateLockVersions(version: number): void {
    document
        .querySelectorAll<HTMLInputElement>('input[name="lock_version"]')
        .forEach((input) => {
            input.value = String(version);
        });
}

function rotateOperationKey(form: HTMLFormElement): void {
    const input = form.querySelector<HTMLInputElement>(
        'input[name="operation_key"]',
    );
    if (input && 'randomUUID' in crypto) {
        input.value = crypto.randomUUID();
    }
}

function toast(message: string, type: 'success' | 'error'): void {
    if (
        typeof window.showToast === 'function' &&
        document.getElementById('toast-container')
    ) {
        window.showToast(message, type);
        return;
    }

    const main = document.querySelector<HTMLElement>(
        '.customer-main, .page-content',
    );
    if (!main) {
        return;
    }

    const notice = document.createElement('div');
    const isCustomerPortal = main.classList.contains('customer-main');
    notice.className = `${isCustomerPortal ? 'customer-axios-toast' : 'ticket-ajax-toast'}${type === 'error' ? ' is-error' : ''}`;
    notice.setAttribute('role', type === 'error' ? 'alert' : 'status');
    notice.textContent = message;
    main.prepend(notice);
    window.setTimeout(() => notice.remove(), 5000);
}

function clearErrors(form: HTMLFormElement): void {
    form.querySelectorAll('[data-axios-error]').forEach((error) =>
        error.remove(),
    );
    form.querySelectorAll('.is-invalid').forEach((field) => {
        field.classList.remove('is-invalid');
        field.removeAttribute('aria-invalid');
    });
}

function showErrors(
    form: HTMLFormElement,
    errors: Record<string, string[]>,
): void {
    Object.keys(errors).forEach((name) => {
        const field = Array.from(form.elements).find((element) => {
            if (
                !(
                    element instanceof HTMLInputElement ||
                    element instanceof HTMLSelectElement ||
                    element instanceof HTMLTextAreaElement
                )
            ) {
                return false;
            }

            return (
                element.name === name ||
                (name.startsWith('attachments.') &&
                    element.name === 'attachments[]')
            );
        }) as
            | HTMLInputElement
            | HTMLSelectElement
            | HTMLTextAreaElement
            | undefined;

        if (!field) {
            return;
        }

        field.classList.add('is-invalid');
        field.setAttribute('aria-invalid', 'true');
    });
}

function appendMessage(message: MessageRecord): void {
    const adminThread = document.querySelector<HTMLElement>(
        '.ticket-conversation-body',
    );
    const customerThread = document.querySelector<HTMLElement>(
        '.customer-conversation-body',
    );
    if (adminThread) {
        appendAdminMessage(adminThread, message);
        updateMessageCount('.ticket-message-count', adminThread);
    } else if (customerThread) {
        appendCustomerMessage(customerThread, message);
        updateMessageCount('.customer-conversation-count', customerThread);
    }
}

function appendAdminMessage(thread: HTMLElement, message: MessageRecord): void {
    const root = ticketRoot();
    const isCustomer = String(message.author_id) === root?.dataset.customerId;
    const isOwn = String(message.author_id) === root?.dataset.currentUserId;
    const article = document.createElement('article');
    article.className = `ticket-message ticket-message-public ${isCustomer ? 'ticket-message-from-customer' : 'ticket-message-from-staff'}${isOwn ? ' ticket-message-own' : ''}`;
    article.dataset.messageId = String(message.id);

    const avatar = document.createElement('div');
    avatar.className = 'ticket-message-avatar';
    avatar.setAttribute('aria-hidden', 'true');
    avatar.textContent = message.author.slice(0, 1).toUpperCase();

    const content = document.createElement('div');
    content.className = 'ticket-message-content';
    const header = document.createElement('header');
    header.className = 'ticket-message-header';
    const author = document.createElement('div');
    author.className = 'ticket-message-author';
    const authorName = document.createElement('strong');
    authorName.textContent = message.author;
    const timestamp = document.createElement('span');
    timestamp.textContent = `${message.created_at} ${ticketRoot()?.dataset.displayTimezone ?? 'Asia/Dhaka'} time`;
    author.append(authorName, timestamp);
    const tags = document.createElement('div');
    tags.className = 'ticket-message-tags';
    const role = document.createElement('span');
    role.className = 'ticket-message-tag ticket-message-tag-public';
    role.textContent = isCustomer ? 'Customer' : 'Support team';
    tags.append(role);
    header.append(author, tags);

    const body = document.createElement('div');
    body.className = 'ticket-message-body';
    body.textContent = message.body;
    content.append(header, body);
    appendAttachments(content, message.attachments);
    if (message.can_moderate && message.moderation_url) {
        content.append(createModerationForm(message));
    }

    article.append(avatar, content);
    thread.querySelector('.ticket-conversation-empty')?.remove();
    thread.append(article);
    article.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function appendCustomerMessage(
    thread: HTMLElement,
    message: MessageRecord,
): void {
    const isOwn =
        String(message.author_id) === ticketRoot()?.dataset.currentUserId;
    const article = document.createElement('article');
    article.className = `customer-chat-message ${isOwn ? 'customer-chat-message-self' : 'customer-chat-message-support'}`;
    article.dataset.messageId = String(message.id);

    const avatar = document.createElement('span');
    avatar.className = 'customer-chat-avatar';
    avatar.setAttribute('aria-hidden', 'true');
    avatar.textContent = message.author.slice(0, 1).toUpperCase();

    const content = document.createElement('div');
    content.className = 'customer-chat-content';
    const header = document.createElement('header');
    header.className = 'customer-chat-meta';
    const author = document.createElement('div');
    author.className = 'customer-chat-author';
    const name = document.createElement('strong');
    name.textContent = message.author;
    const timestamp = document.createElement('time');
    timestamp.textContent = `${message.created_at} Dhaka time`;
    author.append(name, timestamp);
    const role = document.createElement('span');
    role.className = 'customer-chat-role';
    role.textContent = isOwn ? 'You' : 'Support team';
    header.append(author, role);

    const body = document.createElement('p');
    body.className = 'customer-chat-bubble';
    body.textContent = message.body;
    content.append(header, body);
    appendAttachments(content, message.attachments, 'customer-chat-attachment');
    article.append(avatar, content);
    thread.querySelector('.customer-chat-empty')?.remove();
    thread.append(article);
    article.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function appendAttachments(
    container: HTMLElement,
    attachments: AttachmentRecord[],
    className = 'ticket-message-attachment',
): void {
    attachments.forEach((attachment) => {
        if (attachment.preview_url) {
            const preview = document.createElement('a');
            preview.className = 'conversation-image-preview';
            preview.href = attachment.preview_url;
            preview.target = '_blank';
            preview.rel = 'noopener noreferrer';
            preview.setAttribute('aria-label', `Open image ${attachment.name}`);
            const image = document.createElement('img');
            image.src = attachment.preview_url;
            image.alt = attachment.name;
            image.loading = 'lazy';
            image.decoding = 'async';
            const caption = document.createElement('span');
            caption.textContent = 'Open image ↗';
            caption.setAttribute('aria-hidden', 'true');
            preview.append(image, caption);
            const card = document.createElement('figure');
            card.className = 'conversation-image-card';
            const footer = document.createElement('figcaption');
            footer.className = 'conversation-image-footer';
            const meta = document.createElement('div');
            meta.className = 'conversation-image-meta';
            const name = document.createElement('strong');
            name.textContent = attachment.name;
            name.title = attachment.name;
            const size = document.createElement('small');
            size.textContent = `${Math.ceil(attachment.size / 1024)} KiB`;
            meta.append(name, size);
            const download = document.createElement('a');
            download.className = 'conversation-image-download';
            download.href = attachment.url;
            download.title = `Download ${attachment.name}`;
            download.setAttribute('aria-label', download.title);
            download.innerHTML =
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m-4-4 4 4 4-4M4 16v5h16v-5"/></svg>';
            footer.append(meta, download);
            card.append(preview, footer);
            container.append(card);
            return;
        }
        const row = document.createElement('div');
        row.className = className;
        const description = document.createElement('span');
        const name = document.createElement('strong');
        name.textContent = attachment.name;
        const size = document.createElement('small');
        size.textContent = `${Math.ceil(attachment.size / 1024)} KiB`;
        description.append(name, size);
        const download = document.createElement('a');
        if (className === 'customer-chat-attachment') {
            download.className = 'customer-link';
        }
        download.href = attachment.url;
        download.textContent = 'Download';
        row.append(description, download);
        container.append(row);
    });
}

function createModerationForm(message: MessageRecord): HTMLFormElement {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = message.moderation_url ?? '';
    form.className = 'ticket-moderation-form ticket-message-moderation';
    form.dataset.axiosForm = 'moderation';

    const csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = '_token';
    csrf.value = csrfToken;
    const operation = document.createElement('input');
    operation.type = 'hidden';
    operation.name = 'operation_key';
    operation.value = crypto.randomUUID();
    const expected = document.createElement('input');
    expected.type = 'hidden';
    expected.name = 'expected_updated_at';
    expected.value = message.updated_at ?? '';
    const select = document.createElement('select');
    select.name = 'action';
    select.className = 'form-input';
    select.required = true;
    addOption(select, 'hide', 'Hide from customer');
    addOption(select, 'archive', 'Archive');
    const reason = document.createElement('input');
    reason.name = 'reason';
    reason.className = 'form-input';
    reason.required = true;
    reason.minLength = 5;
    reason.maxLength = 500;
    reason.placeholder = 'Reason for moderation';
    const submit = document.createElement('button');
    submit.type = 'submit';
    submit.className = 'btn btn-secondary';
    submit.textContent = 'Moderate message';
    form.append(csrf, operation, expected, select, reason, submit);
    return form;
}

function addOption(
    select: HTMLSelectElement,
    value: string,
    label: string,
): void {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    select.append(option);
}

function updateModeratedMessage(
    form: HTMLFormElement,
    record: ModerationRecord,
): void {
    const article =
        form.closest<HTMLElement>('[data-message-id]') ??
        Array.from(
            document.querySelectorAll<HTMLElement>('[data-message-id]'),
        ).find((row) => row.dataset.messageId === String(record.id));
    const select = form.querySelector<HTMLSelectElement>(
        'select[name="action"]',
    );
    const reason = form.querySelector<HTMLInputElement>('input[name="reason"]');
    const expected = form.querySelector<HTMLInputElement>(
        'input[name="expected_updated_at"]',
    );
    if (!article || !select || !expected) {
        return;
    }

    const action = record.action;
    const tags = article.querySelector(
        '.ticket-message-tags, .ticket-internal-note-meta',
    );
    tags?.querySelector('.ticket-message-tag-muted')?.remove();
    if (record.archived || action === 'archive') {
        appendStateTag(tags, 'Archived');
        select.replaceChildren(new Option('Restore', 'restore'));
    } else if (
        action === 'hide' ||
        (action === 'restore' && !record.is_active)
    ) {
        appendStateTag(tags, 'Hidden');
        select.replaceChildren(
            new Option('Unhide', 'unhide'),
            new Option('Archive', 'archive'),
        );
    } else {
        select.replaceChildren(
            new Option('Hide from customer', 'hide'),
            new Option('Archive', 'archive'),
        );
    }

    expected.value = record.updated_at ?? '';
    if (reason) {
        reason.value = '';
    }
}

function appendStateTag(container: Element | null, label: string): void {
    if (!container) {
        return;
    }
    const tag = document.createElement('span');
    tag.className = 'ticket-message-tag ticket-message-tag-muted';
    tag.textContent = label;
    container.append(tag);
}

function updateAssignment(ticket: TicketPayload): void {
    const root = ticketRoot();
    const value = root?.querySelector<HTMLElement>(
        '.ticket-detail-facts .ticket-fact:nth-child(3) .ticket-fact-value',
    );
    if (value) {
        value.textContent = ticket.assigned_agent ?? 'Unassigned';
    }
}

function updateMessageCount(selector: string, thread: HTMLElement): void {
    const count = thread.querySelectorAll('[data-message-id]').length;
    const badge = document.querySelector<HTMLElement>(selector);
    if (badge) {
        badge.textContent = `${count} ${count === 1 ? 'message' : 'messages'}`;
    }
}

function updateAttachmentStatus(form: HTMLFormElement): void {
    const fileInput = form.querySelector<HTMLInputElement>(
        '.customer-chat-file-input',
    );
    const status = form.querySelector<HTMLElement>('[data-chat-file-status]');
    if (!fileInput || !status) {
        return;
    }

    const count = fileInput.files?.length ?? 0;
    status.textContent =
        count === 0
            ? 'No files'
            : `${count} ${count === 1 ? 'file' : 'files'} selected`;
}
