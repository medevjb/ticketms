import DataTable from 'datatables.net-dt';
import type { AjaxData } from 'datatables.net';
import { data as ticketData } from '@/actions/App/Http/Controllers/Admin/TicketController';

const element = document.querySelector<HTMLTableElement>('#ticket-worklist-table');
const filters = document.querySelector<HTMLFormElement>('#ticket-filters');

if (element && filters) {
    const headers = Array.from(element.querySelectorAll<HTMLTableCellElement>('thead th'));
    const sortInput = filters.elements.namedItem('sort') as HTMLInputElement;
    const directionInput = filters.elements.namedItem('direction') as HTMLInputElement;
    const sortIndex = headers.findIndex((header) => header.dataset.sort === sortInput.value);
    element.querySelector('[data-empty-row]')?.remove();
    DataTable.ext.errMode = 'none';

    const table = new DataTable(element, {
        serverSide: true,
        processing: true,
        searching: false,
        autoWidth: false,
        pageLength: Number(element.dataset.pageLength ?? 15),
        lengthMenu: [15, 30, 50],
        layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },
        order: [[sortIndex < 0 ? headers.length - 2 : sortIndex, directionInput.value === 'asc' ? 'asc' : 'desc']],
        columns: headers.map((header) => ({
            data: header.dataset.column ?? '',
            name: header.dataset.sort ?? '',
            orderable: Boolean(header.dataset.sort),
            searchable: false,
        })),
        ajax(requestData, callback) {
            const request = requestData as AjaxData;
            const params = new URLSearchParams({
                draw: String(request.draw), start: String(request.start), length: String(request.length),
            });
            new FormData(filters).forEach((value, key) => {
                if (typeof value === 'string' && value !== '') params.set(key, value);
            });
            const order = request.order?.[0];
            const sort = order ? headers[Number(order.column)]?.dataset.sort : undefined;
            if (sort && order) {
                params.set('sort', sort);
                params.set('direction', order.dir);
                sortInput.value = sort;
                directionInput.value = order.dir;
            }
            fetch(`${ticketData.url()}?${params.toString()}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            }).then(async (response) => {
                const payload = await response.json();
                if (!response.ok || payload.error) {
                    const message = response.status === 422 && payload.errors
                        ? [...new Set(Object.values(payload.errors as Record<string, string[]>).flat())].join(' ')
                        : 'Tickets could not be loaded. Check your connection and try refreshing the list.';
                    throw new Error(message);
                }
                callback(payload);
            }).catch((error: unknown) => {
                window.showToast?.(error instanceof Error ? error.message : 'Tickets could not be loaded.', 'error');
                callback({ draw: request.draw, data: [], recordsTotal: 0, recordsFiltered: 0 });
            });
        },
        language: {
            emptyTable: 'No tickets match these filters.',
            zeroRecords: 'No tickets match these filters.',
            processing: 'Loading tickets…',
            lengthMenu: 'Show _MENU_ tickets',
            info: '_START_–_END_ of _TOTAL_ tickets',
            infoEmpty: 'No tickets to display',
            infoFiltered: '',
        },
    });

    const pageSize = document.querySelector<HTMLSelectElement>('#ticket-page-size');
    pageSize?.addEventListener('change', () => {
        table.page.len(Number(pageSize.value)).draw();
    });

    const refresh = document.querySelector<HTMLButtonElement>('#refresh-tickets');
    if (refresh) {
        refresh.hidden = false;
        refresh.addEventListener('click', () => table.ajax.reload(undefined, false));
    }
    const fallback = document.querySelector<HTMLElement>('#ticket-pagination-fallback');
    if (fallback) fallback.hidden = true;

    const selectAll = document.querySelector<HTMLInputElement>('#ticket-select-all');
    const updateSelection = () => {
        const checkboxes = Array.from(element.querySelectorAll<HTMLInputElement>('.ticket-select'));
        const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
        const count = document.querySelector('#ticket-selection-count');
        if (count) count.textContent = `${selected} selected`;
        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && selected === checkboxes.length;
            selectAll.indeterminate = selected > 0 && selected < checkboxes.length;
        }
    };
    selectAll?.addEventListener('change', () => {
        element.querySelectorAll<HTMLInputElement>('.ticket-select').forEach((checkbox) => {
            checkbox.checked = selectAll.checked;
        });
        updateSelection();
    });
    element.addEventListener('change', (event) => {
        if ((event.target as HTMLElement).classList.contains('ticket-select')) updateSelection();
    });
    table.on('draw', updateSelection);

    const reload = () => {
        if (!filters.reportValidity()) return;
        const params = new URLSearchParams();
        new FormData(filters).forEach((value, key) => {
            if (typeof value === 'string' && value !== '') params.set(key, value);
        });
        window.history.replaceState({}, '', `${filters.action}?${params.toString()}`);
        table.ajax.reload();
    };
    filters.addEventListener('submit', (event) => {
        event.preventDefault();
        reload();
    });
    filters.querySelectorAll('select, input[type="date"]').forEach((input) => input.addEventListener('change', reload));
    let searchTimer: ReturnType<typeof setTimeout>;
    filters.querySelector('#search')?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(reload, 350);
    });
}
