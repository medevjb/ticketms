<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketBulkAction;
use App\Enums\TicketTransition;
use App\Http\Requests\Tickets\BulkTicketRequest;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\TicketDataTableRequest;
use App\Http\Requests\Tickets\TicketIndexRequest;
use App\Http\Requests\Tickets\TicketPriorityRequest;
use App\Http\Requests\Tickets\TransitionTicketRequest;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Ticket;
use App\Services\CustomerSlaOverviewService;
use App\Services\Tickets\AssignmentService;
use App\Services\Tickets\AttachmentService;
use App\Services\Tickets\ConversationService;
use App\Services\Tickets\SlaService;
use App\Services\Tickets\TicketBulkService;
use App\Services\Tickets\TicketQueryService;
use App\Services\Tickets\TicketService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class TicketController extends BaseController
{
    public function index(TicketIndexRequest $request, TicketQueryService $queries): View
    {
        $actor = $request->user();
        $items = $queries->paginate($actor, $request->validated());
        $mayCreate = in_array('admin', $actor->tyroRoleSlugs(), true) || in_array('customer', $actor->tyroRoleSlugs(), true);

        return view('admin.tickets.index', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'tickets' => $items->withQueryString(),
            'queuePreview' => $queries->unassignedQueuePreview($actor),
            'filters' => array_merge(['search' => '', 'status' => 'active', 'sla_state' => ''], $request->validated()),
            'mayCreate' => $mayCreate,
            'priorities' => Priority::query()->orderBy('sort_order')->get(['id', 'name']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]));
    }

    public function data(TicketDataTableRequest $request, TicketQueryService $queries): JsonResponse
    {
        $filters = $request->validated();
        request()->query->replace($filters);
        request()->replace($filters);
        $isAdministrator = in_array('admin', $request->user()->tyroRoleSlugs(), true);
        $mayBulk = $isAdministrator && $request->user()->can('tickets.bulk');
        $columns = ['ticket', 'subject', 'status', 'priority', 'category', 'sla', 'created', 'actions'];
        if ($isAdministrator) {
            $columns = [...$columns, 'customer', 'assignee'];
        }
        if ($mayBulk) {
            $columns[] = 'selection';
        }

        $query = $queries->filteredQuery($request->user(), $filters)->select([
            'tickets.id', 'number', 'subject', 'status', 'is_active', 'lock_version',
            'priority_id', 'category_id', 'customer_id', 'assigned_agent_id',
            'sla_due_at', 'sla_warning_at', 'created_at',
        ]);
        $table = DataTables::eloquent($query)
            ->filter(fn (): null => null)
            ->order(fn ($query) => $query->orderBy('tickets.'.($filters['sort'] ?? 'created_at'), $filters['direction'] ?? 'desc')->orderBy('tickets.id', $filters['direction'] ?? 'desc'))
            ->setRowId(fn (Ticket $ticket): string => 'ticket-'.$ticket->id)
            ->setRowClass(fn (Ticket $ticket): string => $ticket->assigned_agent_id === null && in_array($ticket->status->value, ['open', 'in_progress', 'waiting_customer'], true) ? 'ticket-unassigned' : '');
        foreach ($columns as $column) {
            $table->addColumn($column, fn (Ticket $ticket): string => view('admin.tickets.partials.list-cell', ['ticket' => $ticket, 'column' => $column])->render());
        }

        return $table->only($columns)->rawColumns($columns)->toJson();
    }

    public function bulk(BulkTicketRequest $request, TicketBulkService $bulk): RedirectResponse
    {
        $data = $request->validated();
        $selectedIds = array_values(array_map('intval', $data['selected_ids']));
        $expectedVersions = array_intersect_key(
            array_map('intval', $data['expected_versions']),
            array_fill_keys($selectedIds, true),
        );
        $result = $bulk->execute(
            $request->user(),
            TicketBulkAction::from((string) $data['action']),
            (string) $data['operation_key'],
            $selectedIds,
            $expectedVersions,
        );
        $redirect = back()->with('success', "Bulk action completed for {$result['succeeded']} tickets.");

        if ($result['failed'] !== []) {
            $redirect->with('bulk_failures', $result['failed']);
        }

        return $redirect;
    }

    public function create(Request $request, TicketService $tickets, CustomerSlaOverviewService $slaOverview): View
    {
        $actor = request()->user();
        $customerSearch = mb_substr(trim((string) $request->query('customer_search', '')), 0, 150);
        $oldCustomerId = old('customer_id');
        $selectedCustomerId = is_scalar($oldCustomerId) && ctype_digit((string) $oldCustomerId)
            ? (int) $oldCustomerId
            : null;

        return view('admin.tickets.create', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'slaOverview' => $slaOverview->summary(),
            ...$tickets->creationOptions($actor, $customerSearch, $selectedCustomerId),
            'customerSearch' => $customerSearch,
        ]));
    }

    public function store(StoreTicketRequest $request, TicketService $tickets, AttachmentService $attachments): RedirectResponse
    {
        $uploads = $request->file('attachments', []);
        $stagedFiles = $attachments->stage(is_array($uploads) ? array_values($uploads) : []);
        $ticket = $tickets->create(
            $request->user(),
            [
                'customer_id' => $request->validated('customer_id'),
                'category_id' => (int) $request->validated('category_id'),
                'priority_id' => (int) $request->validated('priority_id'),
                'subject' => (string) $request->validated('subject'),
                'description' => (string) $request->validated('description'),
            ],
            (string) $request->validated('operation_key'),
            $stagedFiles,
        );

        return to_route('dashboard.tickets.show', $ticket)->with('success', "Ticket {$ticket->number} created.");
    }

    public function show(
        int $ticket,
        TicketService $tickets,
        SlaService $sla,
        AssignmentService $assignments,
        ConversationService $conversations,
    ): View {
        $actor = request()->user();
        $record = $tickets->findForActor($actor, $ticket);

        return view('admin.tickets.show', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'ticket' => $record,
            'messages' => $conversations->messagesFor($actor, $record),
            'sla' => $sla->countdown($record),
            'transitions' => $tickets->availableTransitions($actor, $record),
            'mayEditContent' => $tickets->canEditContent($actor, $record),
            'mayChangePriority' => in_array('admin', $actor->tyroRoleSlugs(), true) && $actor->can('tickets.change_priority'),
            'mayAssign' => in_array('admin', $actor->tyroRoleSlugs(), true) && $actor->can('tickets.assign'),
            'assignableAgents' => in_array('admin', $actor->tyroRoleSlugs(), true) && $actor->can('tickets.assign')
                ? $assignments->assignableAgents($actor)
                : collect(),
            'priorities' => in_array('admin', $actor->tyroRoleSlugs(), true) && $actor->can('tickets.change_priority')
                ? Priority::query()->active()->orderBy('sort_order')->orderBy('id')->get()
                : collect(),
            'mayReply' => $conversations->canReply($actor, $record),
            'mayAddInternalNote' => $conversations->canAddInternalNote($actor, $record),
        ]));
    }

    public function sla(int $ticket, TicketService $tickets, SlaService $sla): JsonResponse
    {
        $record = $tickets->findForActor(request()->user(), $ticket);

        return response()->json($sla->countdown($record))->header('Cache-Control', 'private, no-store');
    }

    public function edit(int $ticket, TicketService $tickets): View
    {
        $actor = request()->user();
        $record = $tickets->findForActor($actor, $ticket);
        abort_unless($tickets->canEditContent($actor, $record), 404);

        return view('admin.tickets.edit', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'ticket' => $record,
        ]));
    }

    public function update(UpdateTicketRequest $request, int $ticket, TicketService $tickets): RedirectResponse
    {
        $record = $tickets->updateContent(
            $request->user(),
            $ticket,
            (string) $request->validated('subject'),
            (string) $request->validated('description'),
            (int) $request->validated('lock_version'),
            (string) $request->validated('operation_key'),
        );

        return to_route('dashboard.tickets.show', $record)->with('success', 'Ticket details updated.');
    }

    public function transition(TransitionTicketRequest $request, int $ticket, TicketService $tickets, AttachmentService $attachments): RedirectResponse
    {
        $uploads = $request->file('attachments', []);
        $stagedFiles = $attachments->stage(is_array($uploads) ? array_values($uploads) : []);
        $record = $tickets->transition(
            $request->user(),
            $ticket,
            TicketTransition::from((string) $request->validated('transition')),
            (int) $request->validated('lock_version'),
            (string) $request->validated('operation_key'),
            $request->validated('public_message'),
            $request->validated('reason'),
            $stagedFiles,
        );

        return to_route('dashboard.tickets.show', $record)->with('success', 'Ticket status updated.');
    }

    public function changePriority(TicketPriorityRequest $request, int $ticket, TicketService $tickets): RedirectResponse|JsonResponse
    {
        $record = $tickets->changePriority(
            $request->user(),
            $ticket,
            (int) $request->validated('priority_id'),
            (int) $request->validated('lock_version'),
            (string) $request->validated('operation_key'),
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Ticket priority and SLA projection updated.',
                'ticket' => [
                    'lock_version' => $record->lock_version,
                    'priority_id' => $record->priority_id,
                    'priority' => $record->priority?->name,
                    'sla_due_at' => $record->sla_due_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M Y, h:i A'),
                ],
            ]);
        }

        return to_route('dashboard.tickets.show', $record)->with('success', 'Ticket priority and SLA projection updated.');
    }
}
