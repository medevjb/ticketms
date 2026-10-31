# Ticket and message reply realtime integration

This guide describes how TicketMS publishes ticket changes and public conversation replies to the customer portal and to authorized ticket participants.

## Components

1. A ticket workflow writes its ticket/message state and an `activity_entries` record in the same transaction.
2. `TicketActivityRecorded` waits until the transaction commits.
3. `BroadcastTicketActivityRealtimeUpdate` loads the committed activity and prepares a minimal, safe payload.
4. `TicketRealtimeUpdated` is queued for broadcast through Reverb on private `ticket.{id}` and `user.{id}` channels.
5. The customer portal uses Laravel Echo to update ticket list rows, show public replies, and synchronize the SLA summary.

The regular Laravel queue worker must be running. Broadcast events use the configured queue connection so request handlers do not wait for Reverb network calls.

## Start the local services

The Reverb install command adds local development credentials to `.env`. For another environment, configure unique `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET` values, then set:

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_HOST=ticketms.test
REVERB_PORT=8080
REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=ticketms.test
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Set `REVERB_ALLOWED_ORIGINS` to the comma-separated hostnames used by your portal. The Reverb server and broadcaster connect to the host in `REVERB_HOST`; browser clients use the matching `VITE_REVERB_*` values.

For the normal local process group, run:

```bash
composer run dev
```

The Laravel dev process starts the server, Vite, Reverb, the queue listener, and the scheduler. To start only the realtime dependencies while Herd serves the app, use separate terminals:

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080 --hostname=ticketms.test
php artisan queue:work database --queue=imports,sla,notifications-database,notifications-mail,default --sleep=1 --tries=3 --timeout=120
npm run dev
```

Restart long-running Reverb and queue processes after changing `.env` or Reverb settings. For a production build, run `npm run build` and serve the built assets. Run Reverb and queue workers under process supervision and configure TLS/reverse proxying for secure WebSockets.

## Private channels and access

`routes/channels.php` authorizes:

- `user.{userId}` only for the authenticated active user with that ID. The customer portal subscribes here to receive updates for its own tickets.
- `ticket.{ticketId}` for the ticket's verified customer, assigned agent with `tickets.view`, or an administrator with `tickets.view`.

Broadcast authorization runs through the authenticated web session and CSRF protection at `/broadcasting/auth`. API bearer tokens authenticate the user-management API separately; they do not grant access to these session channels.

The event sends no ticket description, internal note, storage path, or private attachment URL. A `ticket.message_posted` event is broadcast only when the message visibility is `public` and the message remains active. Internal notes and moderation actions do not publish message contents.

## Event contract

The event is named `ticket.updated` (Echo listener name `.ticket.updated`). Its payload has this shape:

```json
{
  "action": "ticket.message_posted",
  "ticket": {
    "id": 42,
    "number": "TKT-2026-000042",
    "subject": "Cannot access billing",
    "status": "in_progress",
    "priority": "High",
    "assigned_agent": "Sam Lee",
    "lock_version": 8,
    "updated_at": "2026-11-01T03:20:00+00:00",
    "sla_due_at": "2026-11-01T10:00:00+00:00"
  },
  "message": {
    "id": 91,
    "body": "We have applied the fix. Please try again.",
    "author_id": 7,
    "author": "Sam Lee",
    "created_at": "2026-11-01T03:20:00+00:00",
    "attachments": []
  }
}
```

`message` is `null` for ticket state changes. Public reply attachment entries include only `id`, original `name`, `size_bytes`, and `is_image`. Clients must use their authenticated, authorized attachment routes to preview or download files.

The `action` field comes from the ticket activity action. For example, transitions publish `ticket.start`, `ticket.wait`, `ticket.resolve`, `ticket.close`, `ticket.resume`, or `ticket.reopen`; public replies publish `ticket.message_posted`; assignments publish `ticket.assigned`, `ticket.claimed`, or `ticket.auto_assigned`.

## Subscribe from a client

The customer portal already creates Echo in `resources/js/echo.ts` and subscribes in `resources/js/customer-realtime.ts`. A separate authorized client can follow the same pattern after it has authenticated its broadcast request:

```ts
echo.private(`ticket.${ticketId}`).listen('.ticket.updated', (event) => {
    // Update ticket status, assignment, priority, SLA display, or public thread.
});
```

Do not render event strings as HTML. Treat event payloads as data and create DOM nodes with `textContent`. Always continue to use the existing authenticated attachment endpoints for files.

## Test and diagnose

- Use feature tests for successful public replies, ticket status updates, channel authorization, and internal-note privacy.
- Check that `BROADCAST_CONNECTION=reverb` and all `REVERB_*` / `VITE_REVERB_*` values match.
- Confirm both `reverb:start` and `queue:work` are running.
- Inspect browser WebSocket errors and failed `/broadcasting/auth` responses. A `403` from the auth endpoint means the current user is not permitted on that channel.
