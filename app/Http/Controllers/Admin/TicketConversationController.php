<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MessageVisibility;
use App\Http\Requests\Tickets\ModerateMessageRequest;
use App\Http\Requests\Tickets\StoreMessageRequest;
use App\Services\Tickets\AttachmentService;
use App\Services\Tickets\ConversationService;
use HasinHayder\TyroDashboard\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class TicketConversationController extends BaseController
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
                'message' => 'Your message was added.',
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
                    'can_moderate' => $request->user()->can('messages.moderate'),
                    'moderation_url' => $request->user()->can('messages.moderate')
                        ? route('dashboard.tickets.messages.moderate', [$message->ticket_id, $message->getKey()])
                        : null,
                    'attachments' => $message->attachments->map(fn ($attachment): array => [
                        'name' => $attachment->original_name,
                        'size' => $attachment->size_bytes,
                        'url' => route('dashboard.ticket-attachments.download', $attachment),
                    ])->values(),
                ],
            ], 201);
        }

        return to_route('dashboard.tickets.show', $message->ticket_id)->with('success', 'Your message was added.');
    }

    public function moderate(ModerateMessageRequest $request, int $ticket, int $message, ConversationService $conversations): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        $record = $conversations->moderateMessage(
            $request->user(),
            $ticket,
            $message,
            (string) $data['action'],
            (string) $data['reason'],
            (string) $data['expected_updated_at'],
            (string) $data['operation_key'],
        );

        if ($request->expectsJson()) {
            $record->loadMissing('ticket');

            return response()->json([
                'message' => 'Message moderation was saved.',
                'ticket' => [
                    'lock_version' => $record->ticket->lock_version,
                ],
                'record' => [
                    'id' => $record->getKey(),
                    'action' => $data['action'],
                    'is_active' => $record->is_active,
                    'archived' => $record->trashed(),
                    'updated_at' => $record->updated_at?->toIso8601String(),
                ],
            ]);
        }

        return to_route('dashboard.tickets.show', $ticket)->with('success', 'Message moderation was saved.');
    }
}
