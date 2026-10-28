# Background processing

## Execution model

Use Laravel database queues initially; supervised independent workers for `sla`, `notifications-database`, `notifications-mail`, `imports`, and `maintenance`. This separates priority without introducing new infrastructure. Do not start workers, install Horizon or run schedules as part of documentation. Redis/Horizon is a measured future option, not required.

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
| DispatchPendingDeliveries | Query pending/expired leases in chunks 100; claim row atomically, enqueue delivery ID | Dispatcher reruns; lease 5 min; worker timeout60s, reservation90s; expired lease recovered |
| DeliverTicketNotification | Reload recipient active/effective scope; one delivery channel row; stable UUID for database channel | Tries5, backoff10/60/300/900s; permanent forbidden/deleted recipient => skipped; exhausted=>failed and ops alert |
| CheckTicketSla | Candidate IDs chunk 200 by indexed due/warning and running state; lock/recompute; unique cycle-stage | Tries3, backoff5/30s; state changes skip; scan rediscovers pending crossing |
| AssignUnassignedTickets | Finite chunk 100; coordinator + ticket/agent locks; only unassigned live active tickets | Tries3; no eligible agent is valid no-op; scheduled recovery and eligibility changes retry later |
| ProcessTicketImportChunk | At most100 complete CSV records/job and max45s; row/external-ID uniqueness; persist valid checkpoint | Tries3, backoff10/60s; file/schema permanent error=>failed; partial row validation=>continue; terminal failed run manually resumable from checkpoint |
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
| Every minute | Assign finite unassigned batch and recover stalled imports | Independent named tasks; safe idempotency/lease checks |
| Daily | Cleanup abandoned staged files (>24h), temporary import input/error artifacts (>30d after terminal run) | Referenced permanent files excluded; approved limits via config; no ticket/audit deletion |
| Daily | Operational summary/backup verification alert | Do not run actual database backup through app user unless operational design explicitly approves |

The 24h/30d temporary-artifact defaults are proposed operational values; retain import identity/result metadata indefinitely pending retention policy. Import pause does not purge source file; excluded from terminal cleanup until cancelled/completed/failed. Archiving an import run does not cancel a running job; only terminal runs may archive.

Scheduler host invokes Laravel schedule every minute under deployment supervision. All nodes use shared database cache initially; lock TTL longer than task bound, shorter than recovery tolerance. `withoutOverlapping`/unique jobs are dispatch controls, not substitutes for DB uniqueness. Bound each scan by row count/time and carry cursor to prevent starvation. UTC scheduling cadence; domain calendar calculations always pinned Asia/Dhaka.

## Retry and observability

Every job carries entity/operation/correlation identifiers, not full Eloquent graphs or uploaded file bytes. Reload active state and authorization on execution; changed actor permissions may cancel work. Retry transient DB/network/storage failures only; validation, forbidden access, missing permanent files and impossible calendar stop with explicit safe failure.

Set timeout below retry_after with sufficient margin. Proposed ordinary job 60s/reservation90s; import 45s fits same reservation. Scale/adjust with measured data and ensure lease exceeds worker timeout. Monitor queue oldest age by queue, failed jobs, delivery statuses/leases, import heartbeat, SLA detection lag and scheduler heartbeat. Alert admin/operator on failed SLA scans or oldest SLA queue age>60s. Manual retry preserves operation keys and never bypasses policy.

Release evidence: concurrent stage checks produce one escalation/database alert; rollback emits no intents; crash after commit is recovered; disabled recipients skipped; repeated import chunk produces no duplicate tickets; notification bar remains scoped. These tests have not been run during documentation.
