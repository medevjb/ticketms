# Business logic specification

Normative approved behavior for application workflows. IDs BR01–BR16 are traced to requirements and tests. P05 implements public replies, staff-only notes, staged private attachments, scoped downloads, moderation, customer-reply SLA resumption, and explicit reopen-with-message behavior. P08 implements role-scoped analytics using the metric definitions below; consult D12 for delivered scope and tested limits.

Implementation organization: follow the service pattern in [architecture ADR06](04-architecture.md#adr06--service-pattern-for-business-logic). TicketService, AssignmentService, ConversationService and SlaService enforce the same rules for web requests, queued work, imports and bulk operations. The outer atomic operation owns its transaction; module services recheck policy/state and write activity/delivery intent together. The architecture choice does not change these business rules.

## BR01 — Record scope and lifecycle

An active, nondeleted authenticated user is required. Customers own tickets via `customer_id`, not `created_by`. Agents see their assigned tickets and an unassigned queue. Queue preview exposes ticket number, subject, category, priority, creation and SLA summary only; full conversation/files require a successful claim. Admin sees all, including explicit archive views. Generic permissions never widen agent/customer visibility.

Availability is `is_active`, ticket workflow is `status`, archival is `deleted_at`. Disabled tickets are read-only for customers/agents (owner can see banner); admins can reenable or perform corrective work. Their SLA continues unless Waiting/Resolved/Closed. Operational analytics and escalation include disabled live tickets. Soft-deleted tickets must already be Closed; this avoids hiding live obligations.

| Managed module | Enable/disable | Soft delete/restore | Permitted bulk operations |
| --- | --- | --- | --- |
| Users/agents | Deny new/existing sessions, assignment and work; retain ownership/history | Admin archive after unassigning live work; restore disabled pending review | Enable, disable, archive, restore; optional explicit agent eligibility changes |
| Access roles | Custom roles only; changes immediately remove effective grants | Custom roles only; detach grants audibly before archive; restore with no memberships | Enable, disable, archive, restore; no bulk grant-all |
| Categories/priorities | Exclude from new selections | Retain historical FK; restore reserved identity | Enable, disable, archive, restore |
| Calendar/SLA versions | Controls selection; current pointer protected | Draft may archive; published version archived from selection but immutable and retained | Enable/disable/archive/restore eligible versions; publishing individually only |
| Tickets | Read-only switch; never pauses SLA | Closed only; restore same Closed state and disabled until reviewed | Admin assign, priority change, enable/disable, archive/restore; authorized resolve/close where state permits |
| Messages | Admin hide/unhide, customer sees redaction marker for public message | Admin moderation archive/restore with reason; no edit of original text | Admin hide/unhide/archive/restore; internal never converted to public |
| Attachments | Block download without changing parent | Admin archive/restore metadata; bytes retained per approved retention | Disable/enable/archive/restore; authorized scoped download is individual |
| Import runs | Pause/resume at row boundary | Completed/failed/cancelled only; restore history, not replay | Pause/resume/cancel/archive/restore where state permits |
| Notifications module | No arbitrary enable/disable of delivery evidence | Read/archive presentation only through Laravel `read_at`, not data deletion | Mark selected/all own visible notifications read; retry failed delivery admin-only via operations module |
| Audit/SLA/delivery/identity ledgers | Immutable/support exceptions | No ordinary soft deletion | Read/filter, approved retry/acknowledge only; cannot erase traceability |

Last usable administrator cannot be disabled, archived or stripped of admin role; serialize admin membership changes. Admin cannot self-disable/archive. Disabling/deleting an agent atomically removes live assignments (history + delivery intents) under assignment coordinator, leaves tickets unassigned, and allows future auto-allocation. Already resolved/closed assignments remain historical. Owner disable does not delete their tickets or exempt SLAs. Historic user/category links render retained labels without offering inactive selections.

## BR02 — Validation boundaries

Use Form Requests at HTTP boundaries and shared reusable rules/value validation for CSV/services. Recheck mutable eligibility, state, active parent and stale version within transaction. IDs are integers, unique arrays, bounded lengths; no raw SQL sort names.

| Operation/field | Required rule |
| --- | --- |
| Create | Subject trimmed nonblank ≤255 characters; description nonblank ≤20,000; enabled category/priority; customer owner = actor unless admin creates on behalf of an existing active verified customer |
| Reply/note | Body nonblank ≤20,000; public/internal type explicit; only staff note endpoint can set internal; active accessible parent; closed forbids messages |
| Content update | Admin or owning customer with permission; customer only while Open and before first public staff reply; subject/description only; Closed forbids edits; no private content copied into audit diffs |
| Assignment | Existing active nondeleted user with effective agent role; claim requires unassigned active nonterminal ticket and current actor; manual assignment admin only |
| Priority/category edits | Admin or assigned agent with respective permission; active options; priority target exists in ticket's pinned policy; customer cannot alter these after create |
| Status | Explicit transition endpoint, allowed graph, expected lock_version, optional/required reason as specified; never generic arbitrary status mass assignment |
| Configuration | Valid IANA zone (initially fixed Asia/Dhaka); weekday unique 1–7; open < close; at least one weekly window; unique holidays; bounded target 60 seconds–365 business days worth of configured seconds; percent 1–99 |
| Archive/disable/bulk | Reason 5–500 characters for moderation/archive/availability; IDs max 100 unique; action allowlist; per-record authorization and version; no client-controlled actor or role grants |
| Files | Max5 per submission, max10 MiB each; matching allowlisted extension and content MIME; generated storage names; application/proxy request size supports 50 MiB plus overhead |

Validation errors identify field/CSV row; authorization errors do not reveal private records. See contracts for HTTP mappings. Original actor, number, due time, audit and ownership cannot be mass assigned.

## BR03 — Number generation and ticket creation

Generate `TKT-{Dhaka year}-{annual sequence padded to at least six digits}`. Allocate using unique year row and transaction lock; no count-based scheme, no reuse after archive, rollback gaps are acceptable, gaps are not errors. At year boundary use creation instant in Dhaka, not worker completion or UTC year.

Stage/validate private uploads. Within creation transaction acquire assignment coordinator first, select current published policy, pin calendar, find target, compute due/warning, allocate number, create ticket Open, activity and notification intents. Automatic assignment uses BR04 under same coordinator lock. If no eligible candidate commit unassigned and record reason. Reply/import retries supply operation keys; ticket creation browser form uses server-issued operation UUID bound to actor and action to prevent accidental duplicate submission. All retriable mutation commands claim a unique command_receipt in the same transaction as the mutation; receipt binds actor/action/canonical payload hash and committed subject/result. Same key and payload reuses result; mismatched actor/action/payload is generic conflict; rollback leaves no successful receipt. Canonical hashing excludes transport tokens and uses staged file checksums, not temporary paths.

## BR04 — Assignment

Eligible = active nondeleted user with active effective `agent` role, active profile and `auto_assignment_enabled=true` for automatic selection. Manual admin assignment and self-claim need agent role/profile but not automatic flag. No workload cap, category routing or live-presence assumption.

Workload = assigned tickets in Open/In Progress/Waiting for Customer, nondeleted, including disabled tickets. Choose smallest count, then oldest `last_auto_assigned_at` (null first), then smallest user ID. Update timestamp only for successful automatic assignment. Assignment does not change ticket status or reset SLA. Manual reassignment retains status, records old/new assignee and notifies both as appropriate. Repeated same assignment is a no-op, not another history/notification.

All assignment paths use the shared coordinator and ticket locks described in the schema. A self-claim loser gets conflict; never silently reports success with someone else's assignment. Automatic selection runs when a ticket is created; changing eligibility does not move existing tickets, which remain available for admin assignment or agent claim. Existing manual assignments are never stolen automatically.

## BR05 — Ticket state graph

| From | To / trigger | Actor and effects |
| --- | --- | --- |
| Open | In Progress | Assigned agent/admin starts work; running SLA continues |
| Open / In Progress | Waiting for Customer | Assigned agent/admin; public explanation required; open waiting interval and clear due/warning projections |
| Waiting for Customer | In Progress | Customer public reply automatically, or assigned agent/admin explicit resume; close waiting interval, recompute remaining/due |
| Open / In Progress / Waiting for Customer | Resolved | Assigned agent/admin; resolution summary required and public; close waiting interval if any, record crossing before stop, open resolved interval, clear projections |
| Resolved | In Progress | Owner customer/admin reopens with reason; close resolved interval, increment resolution_cycle, retain consumed budget and first breach, notify agent/admin |
| Resolved | Closed | Owner customer/admin; finalize resolved interval at close, retain resolved/closed timestamps and history |
| Closed | None | Final; no reply/reopen/reassignment/priority change; admin can availability/archive/restore only; further issue = new ticket referencing prior number in description |

Public customer replies allowed Open/In Progress/Waiting; waiting reply resumes atomically. Resolved reply request must explicitly reopen with reason and message in one action; no silent reopen merely visiting. Public staff replies do not automatically pause/resume unless action explicitly includes transition. Internal notes never trigger customer notifications or resume SLA. Customer cannot close an unresolved ticket. No automatic close, cancel state or arbitrary backward transition.

## BR06 — Business-time arithmetic

Store UTC, calculate calendar intersections in pinned Asia/Dhaka version. Weekly windows Sunday–Thursday `[09:00,18:00)`; Friday/Saturday and pinned holiday dates have no window. Nine business hours per ordinary day despite 12-hour display format. At exactly 18:00 the next eligible opening is used; a target ending exactly 18:00 is valid. No holidays inferred from current date or government feeds.

Define `B(a,b,calendar)` as integer seconds in intersections of `[a,b)` with eligible local windows. Union of waiting/resolved exclusion intervals is subtracted; prevent overlaps and at most one open interval. Active consumption at instant t:

`C(t) = max(0, B(sla_started_at,t) − B(excluded intervals intersected with [sla_started_at,t)))`.

Resolved/closed do not accrue additional active seconds; closed calculation caps at resolution stop. Waiting freezes consumption at pause. Consumed cache is recomputed/validated at transitions; no timer needs to increment every second.

Target `T` seconds pinned at creation. Warning target `W = ceil(T × warning_percent / 100)`. While running: remaining `max(0,T−C(now))`; add remaining business seconds from now to find due; calculate warning similarly. For already passed thresholds compute original crossing instant by walking eligible windows minus exclusion intervals, not by substituting current worker time. Persist crossing time separate from detection time.

Adding seconds: convert current instant to local date; skip closed days/holidays; clamp start to window opening; consume min(remaining, seconds to close); repeat next date until zero. Iterate bounded dates (maximum configured horizon ten years); reject unusable calendar/policy publication and fail with actionable domain error if no reachable window. No naive `addHours`, no negative arithmetic and no dependence on mutable current calendar.

Examples without holidays:

| Start / condition | Expected |
| --- | --- |
| Thu 2026-10-29 5:00 PM, Critical 2h | Sun 2026-11-01 10:00 AM Dhaka |
| Same with Sun a pinned holiday | Mon 2026-11-02 10:00 AM |
| Fri 2026-10-30 noon, Critical 2h | Sun 2026-11-01 11:00 AM |
| Sun 9:00 AM Critical, wait10:00–Mon10:00 | Consumed1h at pause; resume dueMon11:00 AM |
| Sun9:00 AM Critical, resolve10:00, reopenMon9:00 | Remaining1h, dueMon10:00 AM; resolved interval excluded |

## BR07 — SLA state, priority and version changes

Within = C < W; approaching = W ≤ C < T; breached = C ≥ T while running, and historical crossing retained when stopped. UI distinguishes paused/stopped from current threshold badge and displays first breach/history. At exact due instant breach applies. `first_breached_at` never cleared by reopen or priority reduction; current target performance and ever-breached counts are distinct metrics.

Priority change uses target for new priority in the **same pinned policy** and recomputes original active consumption with all exclusions; no reset and no switch to latest policy. It may immediately breach, or extend current deadline. Record old/new priority/target/deadline and preserve prior breaches. Reject a priority absent from pinned policy rather than guessing; adding new priorities requires future policy support. SLA/calendar edits apply to new tickets only. A future audited recalculation operation is outside initial scope.

## BR08 — Escalation

Approaching at 80%, breached at 100%. Once per `(ticket,resolution_cycle,stage)`; priority changes do not reset dedup keys. If scanner first sees breached, create breach only (no stale approaching notification). Before pause/resolve/priority change evaluate any crossed breach so scheduler races cannot erase history. Reopen already breached => immediate breach entry in new cycle. Notify active assigned agent and active admins; unassigned => admins only. No automatic priority/reassignment. Stage audit records threshold and detection timestamps; alert payload is a safe summary. Scheduler scans running Open/In Progress only; waiting remains frozen.

## BR09 — Configuration and holiday administration

Admin clones a published calendar/policy to draft, edits windows/holidays/targets, previews representative deadlines, validates, and publishes. Publishing a calendar makes its contents immutable but does not change the current policy. Publishing/activating a policy referencing a published calendar locks singleton settings and atomically changes current policy pointer; preserve old immutable versions. Holiday table editing is done inside a draft; it is not an in-place edit to active ticket obligations. Current policy/calendar cannot be disabled or archived without replacement. Existing records show pinned version and admin edits explicitly warn “new tickets only.”

## BR10 — Conversations and files

Messages append-only text, public or internal. Public edit is not initial scope; admin moderation hides/archives with reason without deleting bytes/history. Public redaction shows a neutral marker; internal notes and their attachment metadata never enter the customer message list or ticket-level attachment list. Downloads recheck parent ticket, message visibility, lifecycle and actor scope; disabled/deleted file/message denies download even if path is known. Customers get 404 for internal resources. Store neither message bodies nor original attachment names/paths in activity/notification payloads. File validation failure rejects the entire submission; staged objects are compensated if authorization, idempotency, database, or storage operations fail. Waiting customer public replies close the pause interval and resume SLA inside the same transaction. Resolved public replies require an explicit reopen flag, a reason, and the message in one transaction; Closed remains final.

## BR11 — Bulk actions

List module offers only meaningful operations from BR01. POST body contains operation UUID, action, up to100 distinct record IDs and expected versions, action parameters and reason. Server intersects with authorized scope; authorize each selected item again at execution. Never accept a client query as authority to “select all.”

Create operation record and process IDs ascending. Each item uses same single-record service method and transaction; partial success is deliberate, reported per item. Retry same operation resumes pending items and returns terminal results without duplicate mutations. Derive stable per-item operation UUIDs from the bulk UUID and item identity so command receipts do not collide across items. Tickets require lock_version; other mutable records use expected updated_at with row locking until a dedicated version column is justified. Unrelated forbidden IDs produce generic unavailable results. Transitions resolving/closing require required common summary/reason applied audibly per record. Last-admin, archive state, inactive dependencies and stale versions remain enforced. No broad `whereIn()->update/delete` skipping policies/history. Larger asynchronous selection is future scope, not an unbounded request.

## BR12 — CSV import

Admin-only bulk creation for existing active verified customers; no historical migration. UTF-8 CSV (optional BOM), comma separator, header required, quoted multiline fields supported. Columns: `external_id,customer_email,subject,description,priority,category`; source chosen once per run, not per row. Required fields exact, unknown columns rejected; category/priority use enabled slugs. Imported status Open, creation time processing instant, current SLA policy and auto-assignment rules. No raw assignee/date/status inputs.

Proposed bounds: file 100 MiB, row 500 KiB, subject/body/file validation shared with forms; source 64 and external 128 characters. Normalize source/external trim with documented case-sensitive binary uniqueness; customer email normalizes same as registration. Duplicate external identity anywhere skips without update, even if existing ticket archived. Duplicate within same file also skipped. Row-specific errors do not abort valid rows; malformed CSV/header/encoding stops run with safe diagnostics.

Stream with CSV-aware reader, not line splitting or whole-file collection. Enforce the 500 KiB logical-record cap while parsing, before allocating an unbounded quoted multiline field; over-limit/malformed records fail the run safely if a trustworthy next-record boundary cannot be recovered. Bounded chunks initially 100 rows; persist row and byte boundary checkpoints only after complete CSV records. One ticket transaction per valid row. Crash between commit and checkpoint is safe through external-reference and row uniqueness. Derive stable row command UUID from run ID/row number; source identity additionally prevents duplicates across separate runs. Run pause/cancel checked between rows; committed tickets remain. Result counts reconciled from row ledger; statuses queued/running/paused/completed/completed_with_errors/failed/cancelled. Admin downloads private escaped error CSV; formula-leading text is neutralized. Error files have row number, external ID and field codes, not descriptions or passwords. Imported creation still emits normal notifications; rate-limit mail queue, do not block import on SMTP.

## BR13 — Analytics definitions

Operational metrics include disabled nondeleted records, clearly labeled; archived Closed tickets excluded operationally but retained in admin historical reports. Date filter defaults last30 Dhaka dates, end exclusive at following midnight; operational backlog is current snapshot and labelled as such, not filtered away by creation period. Historical metrics use specified event time, not current status alone.

| Metric | Definition |
| --- | --- |
| Active backlog | Current Open/In Progress/Waiting; counts by status/priority/category; unassigned subset |
| Critical | Active backlog with critical slug |
| SLA approaching/breached | Current running tickets by BR07; paused counts separately; ever-breached distinct |
| Agent workload | BR04 count; assigned agent and unassigned drilldowns |
| Created volume | Tickets created within selected period |
| Resolved/closed throughput | Distinct tickets with resolution/closure activity in period; separate resolution-event count if cycles repeated |
| SLA compliance | Resolution cycles ending in period meeting target at resolution / all resolution cycles ending in period; use audit snapshot of consumed/target, not current recalculated target |
| Resolution time | Median/average active business seconds at resolution for cycles ending in period; sample count and denominator visible |
| Trend/comparison | Daily created/resolved counts; prior equal-length period; percent change undefined denominator shown as N/A |

Admin organization charts/tables with filterable drilldowns; agent own assigned workload/completed cycles plus queue preview count; customer own status/SLA/response summaries. No other-agent/customer names or internal note aggregates in customer metrics. Aggregate permission scoping happens before grouping/caching. No ranking based on undefined response-time SLA; no invented first-response target.

## BR14 — Notifications

Recipients and retry semantics in background-processing. Database bar is own notifications, unread count, mark selected/read-all and authorized ticket links. Revoked access hides entries referencing inaccessible records and suppresses content on click; stored payload intentionally minimal. Notification preferences cannot turn off required SLA/admin alerts in initial scope. Internal notes create staff audit only, no customer delivery.

## BR15 — Failure semantics

Validation422; hidden resource404; known forbidden action403; stale/claim/state conflict409; CSRF419; rate limit429; unexpected500 with correlation ID, no stack/secrets. In Blade validation redirects back with errors/old safe input. Business failures are not retried as infrastructure errors. DB rollback yields no activity or delivery intent; file compensation handles staged objects. Lock retry exhaustion returns retryable conflict/service-unavailable message, never silently succeeds.

## BR16 — Traceability and retention

Every mutation includes actor/system, action, subject, operation/correlation ID, UTC occurrence, safe changes and reason. Resolve activity snapshots cycle, target, consumed seconds, breach flag and pinned versions so analytics remain reproducible. Disabled/deleted records remain administratively attributable. Audits do not copy private message bodies/files/secrets. No force-delete or log-retention purge until legal/security policy approved; do not equate soft delete with privacy erasure or indefinitely retain temporary upload/error files by accident.
