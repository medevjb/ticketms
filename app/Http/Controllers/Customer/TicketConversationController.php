<?php

namespace App\Http\Controllers\Customer;

use App\Enums\MessageVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreMessageRequest;
use App\Services\Tickets\AttachmentService;
use App\Services\Tickets\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class TicketConversationController extends Controller
{
    public function store(StoreMessageRequest $request, int $ticket, AttachmentService $attachments, ConversationService $conversations): RedirectResponse|JsonResponse
    {
        $uploads = $request->file('attachments', []);
        $stagedFiles = $attachments->stage(is_array($uploads) ? array_values($uploads) : []);
        $data = $request->validated();
        $message = $conversations->addMessage(
            $request->user(),
            $ticket,
            (string) $data['body'],
            MessageVisibility::from((string) $data['visibility']),
            (int) $data['lock_version'],
            (string) $data['operation_key'],
            (bool) ($data['reopen'] ?? false),
            isset($data['reason']) ? (string) $data['reason'] : null,
            $stagedFiles,
        );

        if ($request->expectsJson()) {
            $message->loadMissing(['author', 'attachments', 'ticket']);

            return response()->json([
                'message' => 'Your reply was sent.',
                'ticket' => [
                    'lock_version' => $message->ticket->lock_version,
                    'status' => $message->ticket->status->value,
                ],
                'record' => [
                    'id' => $message->getKey(),
                    'body' => $message->body,
                    'visibility' => $message->visibility->value,
                    'author_id' => $message->author_id,
                    'author' => $message->author?->name ?? 'Former user',
                    'created_at' => $message->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A'),
                    'updated_at' => $message->updated_at?->toIso8601String(),
                    'is_active' => $message->is_active,
                    'attachments' => $message->attachments->map(fn ($attachment): array => [
                        'name' => $attachment->original_name,
                        'size' => $attachment->size_bytes,
                        'url' => route('customer.ticket-attachments.download', $attachment),
                        'preview_url' => $attachment->isPreviewableImage() ? route('customer.ticket-attachments.preview', $attachment) : null,
                    ])->values(),
                ],
            ], 201);
        }

        return to_route('customer.tickets.show', $message->ticket_id)->with('success', 'Your reply was sent.');
    }
}
