<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\Tickets\AttachmentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentController extends Controller
{
    public function download(int $attachment, AttachmentService $attachments): StreamedResponse
    {
        return $attachments->download(request()->user(), $attachment);
    }
}
