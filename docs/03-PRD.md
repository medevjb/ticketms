# Product requirements

Version 1.0 · 2026-10-29 · Awaiting final documentation approval.

## Product and scope

A secure, traceable support ticket and business-hour resolution SLA system for one support organization. Administrator, support agent and customer use the integrated Tyro dashboard, with permissions stored in Spatie Laravel Permission and record access enforced by policies and scoped queries. Preserve the existing application and Inertia welcome page.

Release scope includes all 17 source-brief sections plus owner-requested administrative availability controls, soft deletion, bulk actions and role-specific analytics. Excluded: multiple tenants, team/category routing, live presence, inbound email replies, public ticket REST API, historical conversation migration, rich HTML messages, automatic closing, automatic priority escalation and permanent erasure. Do not silently add these later.

## Actors

| Actor | Responsibilities |
| --- | --- |
| Administrator (`admin`) | All ticket access; assignment; settings, calendars, holidays, users, roles; import; audit; analytics; controlled archive/restore |
| Support Agent (`agent`) | Assigned ticket work; unassigned queue preview/claim; public replies; private notes; resolve; scoped analytics |
| Customer (`customer`) | Verified account; create/view own tickets; public replies; close and reopen eligible tickets; own analytics |

Roles are defaults, not scattered controller checks. Permissions enable actions; policies also enforce ownership, assignment and valid state. Custom roles cannot bypass scope simply by possessing a permission.

## Decision register

| ID | Decision | Authority |
| --- | --- | --- |
| D01 | Single organization; customers own individual tickets; agents assigned tickets plus unassigned queue; admins see all | Discussion approved |
| D02 | Existing Laravel/Tyro; MySQL production; Tyro Blade ticket UI; preserve welcome page | Discussion approved; exact server version unverified |
| D03 | Asia/Dhaka, Sunday–Thursday, 9:00 AM–6:00 PM; admin-managed holidays; waiting pauses and customer reply resumes | Explicit owner decision |
| D04 | Low/Medium/High/Critical = 48/24/8/2 business hours; versioned calendars/policies; changes apply to new tickets | Defaults approved by “okey perfect” |
| D05 | Agents/admins resolve; customers/admins close; customer can reopen Resolved; Closed final; original active budget resumes | Discussion approved |
| D06 | Fewest active tickets; administrator-selected eligible agents; least-recent automatic assignment tie-breaker; no category/presence rules | Discussion/defaults approved |
| D07 | Priority change recalculates from original start less recorded non-running intervals; no clock reset; previous breaches retained | Discussion approved |
| D08 | Warn at 80%, breach at 100%; audit plus agent/admin alerts; no automatic priority or assignee changes | Discussion approved |
| D09 | Laravel database and email notifications, shown in a notification bar | Explicit owner decision |
| D10 | Plain text, private PDF/JPEG/PNG/TXT/CSV, 10 MiB/file, 5 files/submission; queued create-only CSV; verified customers and staff 2FA | Proposed defaults approved |
| D11 | Managed module availability, soft deletion, bulk actions; analytical dashboards; Spatie permissions | Explicit latest owner request |
| D12 | Immutable audit/ledger exceptions, archive restrictions, route adapter, exact schema, limits and job defaults | Architect-selected design details for final review; not claims of prior explicit discussion |
| D13 | Service pattern: thin controllers and cohesive domain services under app/Services; shared by HTTP, jobs, imports and bulk; no parallel Actions layer | Explicit owner decision after documentation review |

## Functional requirements and acceptance

| ID | Requirement | Acceptance criteria |
| --- | --- | --- |
| FR01 | Ticket CRUD/worklists | Authorized users create/view/update tickets; separate active and resolved/closed lists, with upcoming work available through the approaching-SLA filter; required fields and public ID shown; no unauthorized customer or agent sees another scoped record. |
| FR02 | Ticket numbering | `TKT-YYYY-NNNNNN` using Dhaka creation year and annual sequence; unique under concurrent creation/import; six digits minimum, grows beyond 999999; never reuses archived numbers. |
| FR03 | Identity and permissions | Email verification required for customer ticket work; staff 2FA required; Spatie is authoritative; disabled/deleted users denied even with existing sessions; every protected write and read has server-side authorization. |
| FR04 | Assignment | Admin assigns/reassigns; eligible agents claim unassigned tickets; automatic selection uses approved workload/tie rules; no candidate leaves ticket unassigned with history. |
| FR05 | Concurrent claim | Two simultaneous claims yield exactly one committed owner and one assignment activity; losing caller receives conflict and refreshed state. |
| FR06 | Conversations | Public replies and staff-only notes are ordered and paginated; customer cannot obtain note text, metadata or files through any route, search, history or notification. |
| FR07 | Attachments | Validate content/extension/count/size; store outside public storage; authorize every download; failed writes clean up staged files; note files remain staff-only. |
| FR08 | SLA | Compute resolution target in business seconds; show budget, due time and within/approaching/breached state; paused tickets show explicit reason and frozen remaining budget. |
| FR09 | Business calendar | Admin publishes immutable calendar versions and manages holidays; deadline crosses weekends/holidays correctly; existing tickets retain their assigned version. UI uses 12-hour times with timezone. |
| FR10 | Escalation | Every minute scan due thresholds; stages recorded once per resolution cycle; alert eligible recipients; repeated/concurrent scans do not duplicate history or database alerts. |
| FR11 | Notifications | Creation, assignment, public replies, resolution, close/reopen and SLA stages produce role-appropriate Laravel mail/database notifications; bar displays own unread count and paginated entries; no note content leaks. |
| FR12 | Search/filter | Ticket number, subject/description keyword, status, priority, category, agent, customer, date and SLA state filter within authorized scope; stable pagination, allowlisted sort and bounded page size. |
| FR13 | CSV import | Admin uploads create-only CSV, sees progress/counts/errors; existing customers only; source+external ID deduplicates across retries/files; valid rows commit independently; no full-file in-memory load. |
| FR14 | Analytics | Admin sees organizational backlog, workload, throughput and SLA performance with definitions/date filters; agents see their work and permitted queue counts; customers see their own metrics; all drilldowns preserve scope. |
| FR15 | Audit | Committed ticket changes record actor, time, action, safe before/after values and correlation; bulk/import/admin actions traceable; no ordinary user can rewrite or delete audit entries. |
| FR16 | Record lifecycle | Each managed module has availability and archive/restore controls where applicable; tickets separate `is_active` from workflow `status`; archived records excluded from normal UI; protected/system records have documented exceptions. |
| FR17 | Bulk actions | Every managed list supports applicable multi-record actions; selected IDs only, max 100 synchronously; validate/authorize each item, preview/confirm, report per-item success/conflict/failure in the response; audit each successful record mutation. Do not persist batch runs or per-item batch results. |
| FR18 | SLA configuration | Admin publishes priority targets/threshold/calendar as immutable policy versions; creation uses current version atomically; disabled priority/category cannot be newly selected but historical links remain readable. |

## Workflow and user experience

- Creation shows: “Resolution SLA counts business hours, Sunday–Thursday, 9:00 AM–6:00 PM (Asia/Dhaka), excluding holidays in the assigned calendar. Waiting for Customer pauses the clock.” Display computed deadline after commit; preview is advisory.
- Waiting banner shows paused since, remaining business time and how replying resumes. No fixed deadline is promised while paused. Resolution shows stopped clock; reopening explains the remaining budget.
- Ticket page separates public conversation and staff notes. Note controls and files are never serialized for customers. Read-only/disabled/archived states are explicit.
- Tyro typography, layouts, components, flash errors, navigation and responsive patterns remain consistent. Forms have labels and field errors; keyboard/focus behavior, mobile table alternatives and non-color status labels are required.
- Bulk toolbar names selected count and action, displays restricted records safely and confirms destructive-in-UI actions (archive/disable). No “select every matching record” initial scope.
- Dashboard cards link to exactly their underlying authorized lists; date ranges and metric definitions are visible; comparison periods use identical duration and calendar timezone.

## Non-functional requirements

| ID | Target and acceptance |
| --- | --- |
| NFR01 | Integrity: transactional ticket mutation + activity + delivery intent; unique sequence/import/escalation constraints; concurrency scenarios tested on MySQL. |
| NFR02 | Security: deny by default, private files, CSRF, validated inputs, staff 2FA, customer verification, scoped analytics, no internal content in customer responses. |
| NFR03 | Capacity: design target 1 million tickets and 50 concurrent active users. Common list/detail p95 server response ≤1 second on a declared staging dataset/hardware; benchmark before claiming compliance. |
| NFR04 | Background latency: escalation detected within 2 minutes under healthy scheduler/workers; mail queued without SMTP in requests. Alert if oldest SLA job age exceeds 60 seconds. |
| NFR05 | Maintainability: Laravel monolith using the service pattern (architecture ADR06); thin controllers, cohesive injected services, shared business rules/transactions across entry points; no parallel Actions layer, vendor edits or duplicated policy/SLA logic; version-specific APIs; no additional packages without implementation approval. |
| NFR06 | Testability: behavioral, boundary, permission, retry and concurrency coverage in [testing strategy](10-testing-strategy.md); CI isolates test databases. |
| NFR07 | Operations: observable failures, replay-safe imports/delivery, encrypted backups and tested restoration; operational objectives require owner sign-off before production. |

## Module standard and protected exceptions

Managed modules: users/agents, access roles, categories, priorities, calendar/policy drafts and versions, tickets, conversation moderation, attachments and import runs. See the per-module matrix in business logic. Availability means usable/read-only, not a second ticket workflow. Soft deletion preserves records; it is not confidentiality erasure. Immutable published versions are archived from selection, not rewritten.

Audit events, SLA intervals/escalations, delivery deduplication, import row identities, sequences, permission definitions/pivots and framework infrastructure are integrity/support data, not ordinary CRUD modules. They must not have arbitrary toggles, soft-delete filters or generic bulk deletion. Their module exposes permitted read/acknowledge/retry operations instead. This safety interpretation of the new blanket standard is explicit and subject to final approval.

## Remaining approval gates

No remaining core business question blocks drafting. Final approval must include D12's detailed standards/exceptions. Before implementation confirm exact MySQL/PHP versions and Spatie major, compatibility spike success, and permission to install that dependency. Before production approve hosting, legal retention/anonymization, backup RPO/RTO, mail service and malware scanning requirement. Do not invent jurisdictional compliance or promise exactly-once external email.
