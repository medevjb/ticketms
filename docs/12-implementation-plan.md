# Implementation plan and validation ledger

Documentation only. No phase below is authorized to execute until owner explicitly approves implementation. Dependency installation, migrations, provisioning, external writes and destructive work retain their separate authorization requirements.

## Ordered delivery

| Phase | Dependencies and tasks | Acceptance gate |
| --- | --- | --- |
| P00 — Baseline | Approve documents/D12 details; establish version control and recovery; verify MySQL 8.0.33 `ticketms`, PHP/packages and vendor migrations; resolve production operational choices | Owner approval recorded; baseline inventory and isolated test DB proven; no existing working feature removed |
| P01 — Identity compatibility | Approved Spatie install; prefix tables; effective permission service/registry; app Role lifecycle; minimal Tyro adapters; verified registration/customer provisioning; staff 2FA; deny incompatible native routes | T03–T05,T21–T22 pass; admin/agent/customer Tyro layouts/auth demonstrated; no dual RBAC; last-admin protection; direct URL denial |
| P02 — Foundation schema/configuration | Users/profile lifecycle, category/priority, immutable calendar/policy versions/targets, singleton pointer, sequence/command receipts/activity; admin screens/bulk permissions | Isolated migration fresh/upgrade tests; FK/unique/checks verified; calendar/holiday publication and current-pointer protection; T10,T18 |
| P03 — Ticket core and SLA | Create/edit/list/detail, numbering, role-scoped queries, status actions, intervals/projections, original budget/priority recalculation | T01–T03,T09–T11,T19; exact worked SLA examples; no naked addHours or arbitrary state writes |
| P04 — Assignment/concurrency | Eligibility/workload selection, manual reassignment, claim, disable-agent unassignment; coordinator/locking/version checks | T07–T08,T21 last-admin concurrency; parallel MySQL proof, no status/SLA reset |
| P05 — Conversation/files | Public replies/internal notes, waiting/resume/reopen, private files, moderation/archive/restore; staged cleanup | T06,T09,T13,T18,T22; customer cannot obtain notes or note file metadata anywhere |
| P06 — Background/notifications | Durable delivery intents, Laravel notifications/database bar, SLA stages/scan, retries/recovery/queue supervision contracts | T12,T14,T19,T23; transaction rollback no delivery; recovered post-commit crash; no duplicate DB alert; mail request path nonblocking |
| P07 — Imports and module bulk | CSV streaming/checkpoints/row errors/external IDs; bulk operation/items; module lifecycle matrix; user/role admin recovery | T15–T18; duplicate archived source ID protected; partial success accurately reported; bounded memory and no bypass of single-record service methods |
| P08 — Analytical dashboards | Admin business metrics/trends/workload/compliance, scoped agent/customer dashboards, date filters/drilldowns, measured cache | T20,T24–T25; explicit denominators/date semantics; cards match lists; no data leakage |
| P09 — Hardening and production readiness | Query plans/load, security/dependency audit, deployment/backup rehearsal, log/queue/scheduler monitoring, responsive review | All critical tests pass; p95 evidence; backup objectives approved/measured; temporary/permanent retention policy approved; owner go-live authorization |

P02 provides foundational draft screens; subsequent modules complete their own bulk/lifecycle UI by P07. Every phase includes validation, authorization, audit and tests for its behavior; do not postpone security/audit until P09. P06 may begin after P03 using stubbed notification transport while assignment/conversations progress sequentially as needed; no separate agents/delegation are required by this plan.

## Task completion contract

Use the service pattern throughout all phases (PRD D13, architecture ADR06). P02 establishes only the needed `app/Services/` conventions; P03 implements TicketService/SlaService, P04 AssignmentService, P05 ConversationService/AttachmentService, P06 NotificationDeliveryService, and P07 import/bulk orchestration services. Admin services are introduced with their modules. Controllers/jobs remain adapters; no parallel Actions layer. Each phase verifies the same service behavior through its real entry points, including authorization, transaction rollback and retry/idempotency.

Each task names FR/BR/T IDs, files/schema affected, permissions and transaction boundary, input/failure cases, minimal test command/results and remaining risks. Update traceability if scope changes. Do not mark phase complete on code presence alone. Verify package versions and applicable skills/rules for each change. No generic infrastructure abstraction until a concrete task needs it.

## Documentation validation ledger

Actual performed validation (2026-10-29):

- Read all 17 source requirement sections, inspected app/source/config/tests/routes/vendor integration and queried schema read-only.
- Confirmed Laravel/Tyro/Inertia/Pest versions through installed Composer metadata. Reviewed Laravel 13 docs through Boost for policies, transactions/locking, queued notifications/after-commit and validation/soft deletion.
- Consulted official Spatie compatibility and Gate customization documentation; package not installed and integration not executed.
- After owner created DB, read-only SQL verified database `ticketms` and server 8.0.33; schema inspection returned no tables. Earlier1049 failure is historical, not current blocker.
- Reviewed requirements, BR rules, schema, permission registry, job catalog and T scenarios for consistency; source17 sections and added module standards are mapped in traceability.
- Automated checks found all 13 new documents plus original 02-initial-requirements.md (14 total), no broken relative Markdown links or unbalanced fences, and no missing FR01–FR18/NFR01–NFR07/BR01–BR16/T01–T25 definitions. Checked source coverage through traceability and original requirements were not edited. No before/after source checksum was captured, so byte-for-byte preservation is not presented as an independent hash check.

Not performed: application tests, new migrations/schema constraints, Spatie installation/compatibility spike, route implementation, browser/visual QA, mail delivery, queue/scheduler execution, EXPLAIN/benchmark, penetration test, backup/restore or deployment. Written acceptance scenarios are planned checks, not executed passing tests.

## Risks and preimplementation decisions

| Item | Disposition |
| --- | --- |
| Spatie/Tyro conflicting role APIs | Known, source-verified; P01 compatibility gate; single authority/adapters specified; stop feature work if proof fails |
| Blanket status/soft delete/bulk vs audit integrity | D12 proposed safe exceptions and explicit module matrix; final owner document approval required |
| MySQL lifecycle/constraints | Current8.0.33 verified; validate generated columns/FKs/checks/fulltext and isolated migration order; current DB initialization not executed |
| Detailed product defaults | CSV create-only,100 MiB limit, role lifecycle, closed-only archive, queue preview, bulk 100, content-edit boundary are baseline design details; final approval covers them |
| Operational choices | Hosting/mail provider/malware scanning/retention/anonymization/RPO/RTO remain production gates, not guessed requirements |
| Version history | `.git` absent during inspection; establish before coding, no commit created here |

## Handoff rule

Owner reviews documents and approves or requests changes. Stop at this point; do not begin P00/P01 automatically. Future approved implementation should deliver reviewable increments in this repository while preserving Tyro and the welcome page.
