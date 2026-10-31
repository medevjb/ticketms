<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Tickets\AssignTicketRequest;
use App\Http\Requests\Tickets\AutoAssignmentRequest;
use App\Http\Requests\Tickets\ClaimTicketRequest;
use App\Services\Tickets\AssignmentService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class TicketAssignmentController extends BaseController
{
    public function agents(): RedirectResponse
    {
        return to_route('dashboard.agents.index');
    }

    public function setAutoAssignment(AutoAssignmentRequest $request, int $agent, AssignmentService $assignments): RedirectResponse|JsonResponse
    {
        $enabled = $request->boolean('auto_assignment_enabled');
        $assignments->setAutoAssignment(
            $request->user(),
            $agent,
            $enabled,
            (string) $request->validated('operation_key'),
        );

        $message = $enabled ? 'Agent enabled for automatic assignment.' : 'Agent removed from automatic assignment.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return back()->with('success', $message);
    }

    public function assign(AssignTicketRequest $request, int $ticket, AssignmentService $assignments): RedirectResponse|JsonResponse
    {
        $record = $assignments->assign(
            $request->user(),
            $ticket,
            (int) $request->validated('agent_id'),
            (int) $request->validated('lock_version'),
            (string) $request->validated('operation_key'),
        );

        if ($request->expectsJson()) {
            $record->loadMissing('assignedAgent');

            return response()->json([
                'message' => "Ticket {$record->number} assigned.",
                'ticket' => [
                    'lock_version' => $record->lock_version,
                    'assigned_agent_id' => $record->assigned_agent_id,
                    'assigned_agent' => $record->assignedAgent?->name,
                ],
            ]);
        }

        return to_route('dashboard.tickets.show', $record)->with('success', "Ticket {$record->number} assigned.");
    }

    public function claim(ClaimTicketRequest $request, int $ticket, AssignmentService $assignments): RedirectResponse
    {
        $record = $assignments->claim(
            $request->user(),
            $ticket,
            (int) $request->validated('lock_version'),
            (string) $request->validated('operation_key'),
        );

        return to_route('dashboard.tickets.show', $record)->with('success', "Ticket {$record->number} claimed.");
    }
}
