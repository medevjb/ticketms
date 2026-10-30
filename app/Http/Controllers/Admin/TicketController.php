<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketBulkAction;
use App\Enums\TicketTransition;
use App\Http\Requests\Tickets\BulkTicketRequest;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\TicketIndexRequest;
use App\Http\Requests\Tickets\TicketPriorityRequest;
use App\Http\Requests\Tickets\TransitionTicketRequest;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Models\Priority;
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
use Illuminate\View\View;

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
        ]));
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

    public function create(TicketService $tickets, CustomerSlaOverviewService $slaOverview): View
    {
        $actor = request()->user();

        return view('admin.tickets.create', $this->getViewData([
            'dashboardRoute' => DashboardRoute::class,
            'slaOverview' => $slaOverview->summary(),
            ...$tickets->creationOptions($actor),
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
            'sla' => $sla->snapshot($record),
            'transitions' => $tickets->availableTransitions($actor, $record),
            'mayEditContent' => $tickets->canEditContent($actor, $record),
            'mayChangePriority' => $actor->can('tickets.change_priority')
                && (in_array('admin', $actor->tyroRoleSlugs(), true) || (int) $record->assigned_agent_id === (int) $actor->getKey()),
            'mayAssign' => in_array('admin', $actor->tyroRoleSlugs(), true) && $actor->can('tickets.assign'),
            'assignableAgents' => in_array('admin', $actor->tyroRoleSlugs(), true) && $actor->can('tickets.assign')
                ? $assignments->assignableAgents($actor)
                : collect(),
            'priorities' => $actor->can('tickets.change_priority')
                ? Priority::query()->active()->orderBy('sort_order')->orderBy('id')->get()
                : collect(),
            'mayReply' => $conversations->canReply($actor, $record),
            'mayAddInternalNote' => $conversations->canAddInternalNote($actor, $record),
        ]));
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
                    'sla_due_at' => $record->sla_due_at?->timezone(config('app.timezone'))->format('d M Y, h:i A'),
                ],
            ]);
        }

        return to_route('dashboard.tickets.show', $record)->with('success', 'Ticket priority and SLA projection updated.');
    }
}
