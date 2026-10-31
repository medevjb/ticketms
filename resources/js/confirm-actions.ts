import '../css/confirmation-dialog.css';

type ConfirmationOptions = {
    title: string;
    message: string;
    context?: string;
    destructive?: boolean;
    confirmLabel?: string;
};
declare global {
    interface Window {
        confirmAction: (options: ConfirmationOptions) => Promise<boolean>;
        showConfirm: (
            title: string,
            message: string,
            options?: { confirmText?: string },
        ) => Promise<boolean>;
        showDanger: (
            title: string,
            message: string,
            options?: { confirmText?: string },
        ) => Promise<boolean>;
    }
}

const dialog = document.querySelector<HTMLDialogElement>(
    '#action-confirmation',
);
window.confirmAction = (options) =>
    new Promise<boolean>((resolve) => {
        if (!dialog || dialog.open) {
            resolve(false);
            return;
        }
        const title = dialog.querySelector('#action-confirmation-title');
        const message = dialog.querySelector(
            '#action-confirmation-description',
        );
        const context = dialog.querySelector<HTMLElement>(
            '[data-confirm-context]',
        );
        const accept = dialog.querySelector<HTMLButtonElement>(
            '[data-confirm-accept]',
        );
        const cancelButtons = Array.from(
            dialog.querySelectorAll<HTMLButtonElement>('[data-confirm-cancel]'),
        );
        if (!accept) {
            resolve(false);
            return;
        }
        if (title) title.textContent = options.title;
        if (message) message.textContent = options.message;
        if (context) {
            context.textContent = options.context ?? '';
            context.hidden = !options.context;
        }
        accept.textContent = options.confirmLabel ?? 'Confirm';
        dialog.dataset.intent = options.destructive ? 'destructive' : 'normal';
        const finish = (confirmed: boolean) => {
            accept.removeEventListener('click', onAccept);
            cancelButtons.forEach((button) =>
                button.removeEventListener('click', onCancel),
            );
            dialog.removeEventListener('cancel', onEscape);
            dialog.close();
            resolve(confirmed);
        };
        const onAccept = () => finish(true);
        const onCancel = () => finish(false);
        const onEscape = (event: Event) => {
            event.preventDefault();
            finish(false);
        };
        accept.addEventListener('click', onAccept);
        cancelButtons.forEach((button) =>
            button.addEventListener('click', onCancel),
        );
        dialog.addEventListener('cancel', onEscape);
        dialog.showModal();
        cancelButtons[cancelButtons.length - 1]?.focus();
    });

window.showConfirm = (title, message, options = {}) =>
    window.confirmAction({ title, message, confirmLabel: options.confirmText });
window.showDanger = (title, message, options = {}) =>
    window.confirmAction({
        title,
        message,
        destructive: true,
        confirmLabel: options.confirmText,
    });

const approved = new WeakSet<HTMLFormElement>();
let pending = false;
if (dialog)
    document.addEventListener(
        'submit',
        (event: SubmitEvent) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            const method = (
                form.querySelector<HTMLInputElement>('input[name="_method"]')
                    ?.value ?? form.method
            ).toUpperCase();
            if (method === 'GET' || method === 'DIALOG') return;
            if (approved.delete(form)) return;
            if (form.dataset.confirmApproved === 'true') {
                delete form.dataset.confirmApproved;
                return;
            }
            if (!form.checkValidity()) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            if (pending) return;
            pending = true;
            const submitter = event.submitter;
            const data = new FormData(form);
            const path = new URL(form.action, location.href).pathname;
            const operation =
                [
                    data.get('action'),
                    data.get('transition'),
                    path.split('/').pop(),
                ].find((value): value is string => typeof value === 'string') ??
                '';
            const destructive =
                method === 'DELETE' ||
                [
                    'archive',
                    'delete',
                    'destroy',
                    'disable',
                    'hide',
                    'close',
                    'cancel',
                ].includes(operation) ||
                data.get('is_active') === '0';
            const labels: Record<string, string> = {
                archive: 'Archive',
                restore: 'Restore',
                disable: 'Disable',
                enable: 'Enable',
                hide: 'Hide',
                unhide: 'Show',
                publish: 'Publish',
                start: 'Start work on',
                wait: 'Pause',
                resume: 'Resume',
                resolve: 'Resolve',
                close: 'Close',
                reopen: 'Reopen',
                cancel: 'Cancel',
            };
            const selected = data.getAll('selected_ids[]').length;
            const buttonLabel =
                submitter?.getAttribute('aria-label') ??
                submitter?.textContent?.trim();
            let title = labels[operation]
                ? `${labels[operation]} this item?`
                : `${buttonLabel || (method === 'DELETE' ? 'Delete this item' : 'Save changes')}?`;
            let message = destructive
                ? 'Please review this action before continuing. Existing records may become unavailable or read-only.'
                : 'Your change will be applied after confirmation. Cancel to keep the current state.';
            if (selected) {
                title = `Apply ${operation} to ${selected} selected ${selected === 1 ? 'record' : 'records'}?`;
                message =
                    'The action applies to the selected records only. Each record is checked before it is updated.';
            }
            if (data.has('auto_assignment_enabled'))
                title = `${data.get('auto_assignment_enabled') === '1' ? 'Enable' : 'Disable'} automatic assignment?`;
            if (path.endsWith('/availability'))
                title = `${data.get('is_active') === '1' ? 'Enable' : 'Disable'} this item?`;
            if (operation === 'start') {
                title = 'Start work on this ticket?';
                message =
                    'The ticket will move to In progress so everyone can see you are working on it.';
            }
            if (path.endsWith('/messages')) {
                title =
                    data.get('visibility') === 'internal'
                        ? 'Add this staff note?'
                        : 'Send this reply?';
                message =
                    data.get('visibility') === 'internal'
                        ? 'This note will be visible to authorized staff only.'
                        : 'This reply and its attachments will be added to the conversation.';
            }
            if (operation === 'publish')
                message =
                    'This version will become read-only after publication. Existing tickets keep their current configuration.';
            const categoryName =
                form.querySelector<HTMLInputElement>(
                    'input[name="name"]',
                )?.value;
            void window
                .confirmAction({
                    title,
                    message,
                    destructive,
                    context: form.dataset.confirmTarget ?? categoryName,
                    confirmLabel: destructive ? 'Confirm action' : 'Confirm',
                })
                .then((confirmed) => {
                    pending = false;
                    if (confirmed && form.isConnected) {
                        approved.add(form);
                        if (
                            (submitter instanceof HTMLButtonElement ||
                                submitter instanceof HTMLInputElement) &&
                            submitter.form === form
                        )
                            form.requestSubmit(submitter);
                        else form.requestSubmit();
                    }
                });
        },
        true,
    );
