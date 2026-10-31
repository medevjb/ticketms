<?php

namespace App\Http\Controllers\Customer;

use App\Enums\TicketTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\TicketIndexRequest;
use App\Http\Requests\Tickets\TransitionTicketRequest;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Services\CustomerSlaOverviewService;
use App\Services\Tickets\AttachmentService;
use App\Services\Tickets\ConversationService;
use App\Services\Tickets\SlaService;
use App\Services\Tickets\TicketQueryService;
use App\Services\Tickets\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TicketController extends Controller
{
    public function index(TicketIndexRequest $request, TicketQueryService $queries, CustomerSlaOverviewService $slaOverview): View
    {
        return view('customer.tickets.index', [
            'tickets' => $queries->paginate($request->user(), $request->validated())->withQueryString(),
            'filters' => array_merge(['search' => '', 'status' => 'active'], $request->validated()),
            'slaOverview' => $slaOverview->summary(),
        ]);
    }

    public function create(TicketService $tickets, CustomerSlaOverviewService $slaOverview): View
    {
        return view('customer.tickets.create', [
            ...$tickets->creationOptions(request()->user()),
            'slaOverview' => $slaOverview->summary(),
        ]);
    }

    public function slaPreview(CustomerSlaOverviewService $slaOverview): JsonResponse
    {
        abort_unless(request()->user()->can('tickets.create'), 403);

        return response()->json($slaOverview->summary())->header('Cache-Control', 'private, no-store');
    }

    public function sla(int $ticket, TicketService $tickets, SlaService $sla): JsonResponse
    {
        return response()->json($sla->countdown($tickets->findForActor(request()->user(), $ticket)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreTicketRequest $request, TicketService $tickets, AttachmentService $attachments): RedirectResponse
    {
        $uploads = $request->file('attachments', []);
        $stagedFiles = $attachments->stage(is_array($uploads) ? array_values($uploads) : []);
        $ticket = $tickets->create(
            $request->user(),
            [
                'category_id' => (int) $request->validated('category_id'),
                'priority_id' => (int) $request->validated('priority_id'),
                'subject' => (string) $request->validated('subject'),
                'description' => (string) $request->validated('description'),
            ],
            (string) $request->validated('operation_key'),
            $stagedFiles,
        );

        return to_route('customer.tickets.show', $ticket)->with('success', "Ticket {$ticket->number} created.");
    }

    public function show(
        int $ticket,
        TicketService $tickets,
        SlaService $sla,
        ConversationService $conversations,
        CustomerSlaOverviewService $slaOverview,
    ): View {
        $actor = request()->user();
        $record = $tickets->findForActor($actor, $ticket);

        return view('customer.tickets.show', [
            'ticket' => $record,
            'messages' => $conversations->messagesFor($actor, $record),
            'sla' => $sla->countdown($record),
            'serviceAvailability' => $record->businessCalendar ? $slaOverview->availability($record->businessCalendar) : null,
            'transitions' => $tickets->availableTransitions($actor, $record),
            'mayEditContent' => $tickets->canEditContent($actor, $record),
            'mayReply' => $conversations->canReply($actor, $record),
        ]);
    }

    public function edit(int $ticket, TicketService $tickets): View
    {
        $actor = request()->user();
        $record = $tickets->findForActor($actor, $ticket);
        abort_unless($tickets->canEditContent($actor, $record), 404);

        return view('customer.tickets.edit', ['ticket' => $record]);
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

        return to_route('customer.tickets.show', $record)->with('success', 'Ticket details updated.');
    }

    public function transition(TransitionTicketRequest $request, int $ticket, TicketService $tickets, AttachmentService $attachments): RedirectResponse
    {
        $data = $request->validated();
        $uploads = $request->file('attachments', []);
        $stagedFiles = $attachments->stage(is_array($uploads) ? array_values($uploads) : []);
        $record = $tickets->transition(
            $request->user(),
            $ticket,
            TicketTransition::from((string) $data['transition']),
            (int) $data['lock_version'],
            (string) $data['operation_key'],
            $data['public_message'] ?? null,
            $data['reason'] ?? null,
            $stagedFiles,
        );

        return to_route('customer.tickets.show', $record)->with('success', 'Ticket status updated.');
    }
}
