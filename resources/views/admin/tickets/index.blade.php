@extends('tyro-dashboard::layouts.admin')
@section('title', 'Tickets')
@section('breadcrumb')<a href="{{ route($dashboardRoute::name('index')) }}">
    Dashboard</a><span class="breadcrumb-separator">/</span><span>Tickets</span>@endsection
@push('styles')
    @vite('resources/css/ticket-table.css')
@endpush
@section('content')
    @php
        $isAdmin = in_array('admin', auth()->user()->tyroRoleSlugs(), true);
        $mayBulk = $isAdmin && auth()->user()->can('tickets.bulk');
        $columns = [];
        if ($mayBulk) {
            $columns['selection'] = 'Select';
        }
        $columns += [
            'ticket' => 'Ticket',
            'subject' => 'Subject',
            'status' => 'Status',
            'priority' => 'Priority',
            'category' => 'Category',
        ];
        if ($isAdmin) {
            $columns += ['customer' => 'Customer', 'assignee' => 'Assignee'];
        }
        $columns += ['sla' => 'SLA', 'created' => 'Created', 'actions' => 'Actions'];
    @endphp
    <div class="ticket-worklist">
        <header class="page-header">
            <div class="page-header-row">
                <div>
                    <h1 class="page-title">Tickets</h1>
                    <p class="page-description">Your support worklist. Spot urgent requests, assign ownership, and keep SLA
                        commitments on track.</p>
                </div>
                <div class="ticket-toolbar-actions">
                    @if (auth()->user()->can('agents.manage'))
                        <a class="btn btn-secondary" href="{{ route('dashboard.agents.index') }}">Manage agents</a>
                        @endif @if ($mayCreate)
                            <a class="btn btn-primary" href="{{ route('dashboard.tickets.create') }}">Create ticket</a>
                        @endif
                </div>
            </div>
        </header>
        <section class="card">
            <div class="card-body">
                <form id="ticket-filters" method="GET" action="{{ route('dashboard.tickets.index') }}"
                    class="ticket-filter-grid">
                    <div class="ticket-search-field"><label class="form-label" for="search">Search tickets</label><input
                            id="search" type="search" name="search" class="form-input" maxlength="150"
                            value="{{ $filters['search'] ?? '' }}" placeholder="Ticket number, subject or keywords"></div>
                    <div><label class="form-label" for="status">Worklist</label><select id="status" name="status"
                            class="form-input">
                            @foreach (['active' => 'Active tickets', 'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All tickets'] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['status'] ?? 'active') === $value)>{{ $label }}
                                </option>
                                @endforeach @if ($isAdmin)
                                    <option value="archived" @selected(($filters['status'] ?? '') === 'archived')>Archived</option>
                                @endif
                        </select>
                    </div>
                    @if ($isAdmin)
                        <div><label class="form-label" for="assignment">Assignment</label><select id="assignment"
                                name="assignment" class="form-input">
                                <option value="">All assignments</option>
                                <option value="unassigned" @selected(($filters['assignment'] ?? '') === 'unassigned')>Unassigned only</option>
                                <option value="assigned" @selected(($filters['assignment'] ?? '') === 'assigned')>Assigned only</option>
                            </select></div>
                    @endif
                    <div><label class="form-label" for="priority_id">Priority</label><select id="priority_id"
                            name="priority_id" class="form-input">
                            <option value="">All priorities</option>
                            @foreach ($priorities as $priority)
                                <option value="{{ $priority->id }}" @selected((string) ($filters['priority_id'] ?? '') === (string) $priority->id)>{{ $priority->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <details class="ticket-more-filters"
                        {{ collect(['category_id', 'sla_state', 'date_from', 'date_to'])->contains(fn($key) => !empty($filters[$key])) ? 'open' : '' }}>
                        <summary>More filters</summary>
                        <div class="ticket-secondary-filters">
                            <div><label class="form-label" for="category_id">Category</label><select id="category_id"
                                    name="category_id" class="form-input">
                                    <option value="">All categories</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $category->id)>
                                            {{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div><label class="form-label" for="sla_state">SLA</label><select id="sla_state"
                                    name="sla_state" class="form-input">
                                    <option value="">Any SLA state</option>
                                    @foreach (['within' => 'Within SLA', 'approaching' => 'Approaching', 'breached' => 'Breached', 'paused' => 'Paused', 'stopped' => 'Stopped'] as $value => $label)
                                        <option value="{{ $value }}" @selected(($filters['sla_state'] ?? '') === $value)>
                                            {{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div><label class="form-label" for="date_from">Created from</label><input id="date_from"
                                    type="date" name="date_from" class="form-input"
                                    value="{{ $filters['date_from'] ?? '' }}"></div>
                            <div><label class="form-label" for="date_to">Created to</label><input id="date_to"
                                    type="date" name="date_to" class="form-input"
                                    value="{{ $filters['date_to'] ?? '' }}"></div>
                        </div>
                    </details>
                    <input type="hidden" name="sort" value="{{ $filters['sort'] ?? 'created_at' }}"><input
                        type="hidden" name="direction" value="{{ $filters['direction'] ?? 'desc' }}">
                    @foreach (['agent_id', 'customer_id'] as $retainedFilter)
                        @if (isset($filters[$retainedFilter]))
                            <input type="hidden" name="{{ $retainedFilter }}" value="{{ $filters[$retainedFilter] }}">
                        @endif
                    @endforeach
                    <div class="ticket-toolbar-actions"><button class="btn btn-secondary" type="submit">Apply
                            filters</button><a class="btn btn-ghost"
                            href="{{ route('dashboard.tickets.index') }}">Clear</a></div>
                </form>
            </div>
        </section>
        @if ($queuePreview->isNotEmpty())
            <section class="card">
                <div class="card-body">
                    <h2 class="section-title">Unassigned queue preview</h2>
                    <p class="page-description">Claim a request to take ownership. Customer conversations and private files
                        become available after assignment.</p>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Ticket</th>
                                    <th>Subject</th>
                                    <th>Category</th>
                                    <th>Priority</th>
                                    <th>SLA due</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($queuePreview as $preview)
                                    <tr class="ticket-unassigned">
                                        <td>{{ $preview->number }}</td>
                                        <td><span
                                                title="{{ $preview->subject }}">{{ str($preview->subject)->limit(64) }}</span>
                                        </td>
                                        <td><span
                                                title="{{ $preview->category?->name }}">{{ str($preview->category?->name)->limit(36) }}</span>
                                        </td>
                                        <td>@include('admin.tickets.partials.list-cell', [
                                            'ticket' => $preview,
                                            'column' => 'priority',
                                        ])</td>
                                        <td>{{ $preview->sla_due_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M, h:i A') }}
                                        </td>
                                        <td>
                                            <form method="POST"
                                                action="{{ route('dashboard.tickets.claim', $preview) }}">@csrf
                                                @method('PATCH')<input type="hidden" name="operation_key"
                                                    value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input
                                                    type="hidden" name="lock_version"
                                                    value="{{ $preview->lock_version }}"><button
                                                    class="btn btn-secondary" type="submit">Claim ticket</button></form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        @endif
        <section class="card ticket-list-panel">
            <div class="card-body">
                <div class="ticket-list-heading">
                    <div class="ticket-list-context">
                        <label class="ticket-page-size" for="ticket-page-size">Show <select id="ticket-page-size"
                                name="per_page" form="ticket-filters" class="form-input" aria-label="Tickets per page">
                                @foreach ([15, 30, 50] as $pageSize)
                                    <option value="{{ $pageSize }}" @selected($tickets->perPage() === $pageSize)>{{ $pageSize }}
                                    </option>
                                @endforeach
                            </select>
                            items</label>
                        <small class="page-description"
                            title="Display timezone">{{ config('ticketing.display_timezone', 'Asia/Dhaka') }}</small>
                        <button class="btn btn-ghost admin-icon-button" type="button" id="refresh-tickets"
                            title="Refresh tickets" aria-label="Refresh tickets"
                            hidden>@include('admin.action-icon', ['icon' => 'refresh'])</button>
                    </div>
                    @if ($mayBulk)
                        <form id="ticket-bulk-form" method="POST" action="{{ route('dashboard.tickets.bulk') }}"
                            class="ticket-bulk-toolbar">
                            @csrf<input type="hidden" name="operation_key"
                                value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <span id="ticket-selection-count" class="page-description" aria-live="polite">0
                                selected</span>
                            <select id="bulk_action" name="action" class="form-input"
                                aria-label="Selected ticket action" required>
                                <option value="">Bulk action</option>
                                <option value="enable">Enable</option>
                                <option value="disable">Disable</option>
                                <option value="archive">Archive closed tickets</option>
                                <option value="restore">Restore archived tickets</option>
                            </select>
                            <button class="btn btn-secondary" type="submit">Apply</button>
                        </form>
                    @endif
                </div>
                <div class="table-responsive">
                    <table id="ticket-worklist-table" class="table" data-page-length="{{ $tickets->perPage() }}">
                        <thead>
                            <tr>
                                @foreach ($columns as $column => $label)
                                    <th scope="col" data-column="{{ $column }}"
                                        data-sort="{{ match ($column) {'ticket' => 'number','sla' => 'sla_due_at','created' => 'created_at',default => ''} }}">
                                        @if ($column === 'selection')
                                            <input type="checkbox" id="ticket-select-all"
                                                aria-label="Select all tickets on this page">@else{{ $label }}
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($tickets as $ticket)
                                <tr
                                    class="{{ $ticket->assigned_agent_id === null && in_array($ticket->status->value, ['open', 'in_progress', 'waiting_customer'], true) ? 'ticket-unassigned' : '' }}">
                                    @foreach ($columns as $column => $label)
                                        <td>@include('admin.tickets.partials.list-cell', [
                                            'ticket' => $ticket,
                                            'column' => $column,
                                        ])</td>
                                    @endforeach
                                </tr>
                            @empty<tr data-empty-row>
                                    <td colspan="{{ count($columns) }}">No tickets match these filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div id="ticket-pagination-fallback">{{ $tickets->links() }}</div>
                <noscript>
                    <p class="page-description">Use the filters and page links to navigate tickets.</p>
                </noscript>
            </div>
        </section>
    </div>
@endsection
@push('scripts')
    @vite('resources/js/ticket-table.ts')
@endpush
