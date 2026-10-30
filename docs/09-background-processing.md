# Background processing

## Implemented scope (P06)

The application now has `DeliverTicketNotification` and `EvaluateTicketSla` jobs, `NotificationDeliveryService` and `TicketEscalationService`, `ticketing:dispatch-notifications` and `ticketing:scan-sla` commands, and minute schedules for both commands. Ticket activity is dispatched after commit; notification intents are written with the activity transaction and recovered by the periodic dispatcher. No schema change was needed: the existing `notification_deliveries`, `notifications`, and unique escalation cycle/stage schema are used.

Database and mail are delivered as separate jobs to `notifications-database` and `notifications-mail`. The delivery service atomically claims a row with an 85-second lease, rechecks recipient account/permission/current ticket scope, and persists retry count and backoff. Its durable retry policy is five attempts with configured delays of 10/60/300/900 seconds; terminal failures retain a safe exception class code. The queue job itself has a 60-second timeout and three framework attempts for uncaught worker failures. Database notifications reuse the intent UUID and detect an already-inserted row. Mail transport cannot guarantee exactly-once delivery if the provider accepts a message and the worker dies before recording success.

The SLA command selects bounded due candidates using persisted warning/due projections and a rotating cache cursor. Unique per-ticket jobs recompute under a ticket row lock; `ticket_escalations` enforces one stage per ticket/resolution-cycle/stage. A late first scan records only the currently reached highest stage. Breach activity/intents are committed with the escalation; notifications go to the assigned agent and active administrators, never the customer. Wait/resolution/closed states are excluded by the evaluator, and state-changing services record an already-due stage before changing the SLA state.

The notification center routes through `notifications.view`, scopes each row against current ticket access, paginates, and limits read/read-all operations to the actor. The Tyro topbar count is loaded with scoped unread-count logic. Queue supervision, actual SMTP, shared production cache locks, service monitoring/alerts, and MySQL concurrency tests remain deployment/hardening work, not verified by the focused SQLite suite.

## Execution model

Use Laravel database queues initially. The implemented queues are `sla`, `notifications-database`, `notifications-mail`, and `imports`. Ticket import chunks use resumable row/byte checkpoints and `ticketing:recover-imports` scans for stalled runs every five minutes. Supervise independent workers to separate priority without introducing new infrastructure. Redis/Horizon is a measured future option, not required.

Business services synchronously commit core invariants, activity and notification-delivery intent. Events represent committed facts; listeners perform secondary work, not required ownership/audit writes. Transactional intent plus periodic recovery prevents lost notifications after a commit-to-dispatch crash.

Jobs/listeners use the same injected services as web controllers (architecture ADR06): CheckTicketSla delegates to SlaService, assignment recovery to AssignmentService, import chunks to TicketImportService (which calls TicketService), and delivery workers to NotificationDeliveryService. The job owns queue retry/timeout behavior; the service owns the business operation and atomic transaction. Do not duplicate calculations, policy checks or ticket mutation logic in job handlers.

## Events and responsibilities

| Event (after commit) | Synchronous transaction responsibility | Secondary behavior |
| --- | --- | --- |
| TicketCreated | Number, SLA snapshot/projections, optional assignment, activity/intents | Dispatch pending delivery processing; invalidate scoped analytics cache |
| TicketAssigned / Unassigned | One assignee, workload eligibility, history/intents | Recipient delivery, queue availability refresh |
| PublicReplyAdded | Message/files, optional resume, activity/intents | Notify opposite participant; invalidate conversation summary |
| TicketResolved / Closed / Reopened | Interval/state/cycle snapshot/history/intents | Delivery, metric cache invalidation |
| TicketSlaStageReached | Unique escalation + threshold/detection + history/intents | Agent/admin alert delivery |
| RecordAvailabilityChanged / Archived / Restored | Lifecycle guards, reassignment if applicable, audit | Scope/cache invalidation; no public content leak |
| ImportCompleted | Final row-derived totals/state | Admin completion notification |

InternalNoteAdded is staff-only history; no customer notification. Prefer framework event discovery with typed listeners; check registration/event cache to prevent duplicate listener registration. Use Laravel 13 after-commit events/jobs/notifications or connection setting; current configuration is false and requires an intentional future change. Do not rely solely on after-commit dispatch for durable handoff: persisted delivery intents remain recoverable.

## Job catalog

| Job/command | Bounds and idempotency | Failure and recovery |
| --- | --- | --- |
| `ticketing:dispatch-notifications` | Query up to configured batch (default 100) of due pending or expired-lease rows; enqueue delivery ID | Dispatcher reruns; delivery service lease 85s; expired processing rows become candidates again |
| `DeliverTicketNotification` | One durable channel row; reload recipient active/effective scope; stable UUID for database channel | Durable tries 5, backoff 10/60/300/900s; no longer authorized/deleted recipient => skipped; exhausted => failed |
| `ticketing:scan-sla` / `EvaluateTicketSla` | Candidate IDs up to configured batch (default 200), rotating cursor; lock/recompute; unique cycle-stage | Job retries 3 times for uncaught failures; state changes skip; periodic scan rediscovers candidates |
| AssignUnassignedTickets | Finite chunk 100; coordinator + ticket/agent locks; only unassigned live active tickets | Tries3; no eligible agent is valid no-op; scheduled recovery and eligibility changes retry later |
| ProcessTicketImportChunk | At most100 complete CSV records/job; job timeout110s; row/external-ID uniqueness; persist valid checkpoint | Tries3, backoff10/60s; file/schema permanent error=>failed; partial row validation=>continue; terminal failed run manually resumable from checkpoint |
| ExecuteBulkOperation | Initial ≤100 items may run synchronously; if future queued, same item ledger/action | Retry pending only; current actor permission rechecked; revoked actor stops remaining items |
| CleanupTemporaryArtifacts | Daily bounded objects with ledger/age; never delete referenced retained attachments/audit | Tries3; storage errors logged/retried; no broad storage glob purge |

Chunk jobs chain next job only after checkpoint commit; periodic recovery finds running import with stale heartbeat/checkpoint and no current lease. Store safe failure code and correlation, not raw exception in user report. Limit per-run parallelism to one initially so byte/row checkpoints are deterministic. Worker-death after committed row replays harmlessly through ledger.

## Notification recipients and channels

Use Laravel Notification classes with `database` and `mail` delivery, safe stable payload and explicit queue/channel selection. Channel jobs may use Laravel `via()`/`viaQueues()`/custom database channel as required to honor the durable-delivery UUID. Calling `notify()` on both channels for each channel row would double-deliver; per delivery choose only its channel. Confirm installed Laravel Notification API before coding.

| Event | Recipients |
| --- | --- |
| Ticket created | Owner confirmation; assigned agent if auto-assigned; admins if unassigned |
| Assignment/reassignment | Owner and new assignee; former assignee receives reassignment notice; deduplicate owner/actor overlap |
| Customer public reply | Current assigned agent, or admins when unassigned |
| Agent/admin public reply | Owner customer |
| Resolved/Closed | Owner and assigned agent, excluding actor duplicates |
| Reopened | Assigned agent and admins; owner confirmation only if another actor reopened |
| Approaching/breached | Assigned agent and admins; unassigned=>admins; no customer escalation email initially |
| Import completed/failed | Initiating admin, if still active and authorized |
| Internal notes / internal moderation | No customer delivery; staff audit only |

Use active administrators at event time, distinct user IDs, with recheck before send. Mandatory alerts cannot be disabled by personal preference initially. Sender is configured organization address; customer content never controls mail headers. Link is a named authenticated route; reauthorize on click. Database payload: schema_version, event/action, number, ticket_id, safe summary, activity ID, occurrence time. No note/body, private filename, filesystem path or role/security secret.

Persist each `(activity,recipient,channel)` intent atomically with activity. For database channel insert using stable notification UUID; duplicate insert is already delivered. Email cannot be proven exactly-once across SMTP send/ack crash: retry may duplicate. Record send attempts and provider message ID when available, use provider idempotency if supported, and never claim exactly-once transport. A failed email does not roll back ticket state or block database bar delivery.

## Scheduler contract

| Frequency | Task | Controls |
| --- | --- | --- |
| Every minute | Scan approaching/breach candidates and dispatch bounded SLA jobs | withoutOverlapping, onOneServer with shared lock store; unique per-ticket stage guard remains authoritative |
| Every minute | Dispatch pending notification intents, recover expired leases | Bounded batches; no SMTP in scheduler process |
| Every five minutes | Recover stalled imports | Re-dispatch the current checkpoint; run and row uniqueness prevent duplicate ticket creation |
| Planned | Assign finite unassigned batch | Independent named task; safe idempotency/lease checks |
| Daily | Cleanup abandoned staged files (>24h), temporary import input/error artifacts (>30d after terminal run) | Referenced permanent files excluded; approved limits via config; no ticket/audit deletion |
| Daily | Operational summary/backup verification alert | Do not run actual database backup through app user unless operational design explicitly approves |

The 24h/30d temporary-artifact defaults are proposed operational values; retain import identity/result metadata indefinitely pending retention policy. Import pause does not purge source file; excluded from terminal cleanup until cancelled/completed/failed. Archiving an import run does not cancel a running job; only terminal runs may archive.

Scheduler host invokes Laravel schedule every minute under deployment supervision. All nodes use shared database cache initially; lock TTL longer than task bound, shorter than recovery tolerance. `withoutOverlapping`/unique jobs are dispatch controls, not substitutes for DB uniqueness. Bound each scan by row count/time and carry cursor to prevent starvation. UTC scheduling cadence; domain calendar calculations always pinned Asia/Dhaka.

## Retry and observability

Every job carries entity/operation/correlation identifiers, not full Eloquent graphs or uploaded file bytes. Reload active state and authorization on execution; changed actor permissions may cancel work. Retry transient DB/network/storage failures only; validation, forbidden access, missing permanent files and impossible calendar stop with explicit safe failure.

Set timeout below retry_after with sufficient margin. Proposed ordinary job 60s/reservation90s; import 45s fits same reservation. Scale/adjust with measured data and ensure lease exceeds worker timeout. Monitor queue oldest age by queue, failed jobs, delivery statuses/leases, import heartbeat, SLA detection lag and scheduler heartbeat. Alert admin/operator on failed SLA scans or oldest SLA queue age>60s. Manual retry preserves operation keys and never bypasses policy.

P06 focused evidence: SQLite feature tests pass for stable database UUID/repeat delivery, per-channel queue routing, current-scope inbox/read behavior, approaching/breached deduplication, and late first scan breach-only behavior. Adjacent conversation/workflow/assignment tests also pass. No actual SMTP, simultaneous MySQL escalation workers, queue daemon recovery, deployment scheduler lock, or crash-process test was run. Rollback/no-intent and full failed-mail/recovery acceptance remain planned.
