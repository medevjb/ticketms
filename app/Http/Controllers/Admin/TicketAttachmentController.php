<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Tickets\ModerateAttachmentRequest;
use App\Services\Tickets\AttachmentService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentController extends BaseController
{
    public function download(int $attachment, AttachmentService $attachments): StreamedResponse
    {
        return $attachments->download(request()->user(), $attachment);
    }

    public function moderate(ModerateAttachmentRequest $request, int $ticket, int $attachment, AttachmentService $attachments): RedirectResponse
    {
        $data = $request->validated();
        $attachments->moderate(
            $request->user(),
            $ticket,
            $attachment,
            (string) $data['action'],
            (string) $data['reason'],
            (string) $data['expected_updated_at'],
            (string) $data['operation_key'],
        );

        return to_route('dashboard.tickets.show', $ticket)->with('success', 'Attachment moderation was saved.');
    }
}
