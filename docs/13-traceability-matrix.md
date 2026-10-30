# Requirements traceability

Source references are section numbers in [02-initial-requirements.md](02-initial-requirements.md). The source brief has been standardized for readability without changing its meaning or section mapping. FR/NFR acceptance is in PRD; BR behavior is in business-logic; test scenarios and executed evidence are recorded in testing-strategy and the D12 ledger. Components may be implemented or planned; current delivery status is recorded in D12.

| Source | Requirement | Rules | Database components | Application components | Tests |
| --- | --- | --- | --- | --- | --- |
| 01 Ticket management | FR01 | BR01–BR03,BR05 | tickets,command_receipts,activity_entries | TicketController,Form Requests,TicketPolicy,ticket services | T01,T03,T09,T18,T19 |
| 02 Ticket numbering | FR02 | BR03 | ticket_sequences,tickets.number | TicketService::create,number allocator | T02,T19 |
| 03 Roles/permissions | FR03 | BR01,BR02,BR16 | users,access roles/permissions/pivots,bulk operations | EffectivePermissionService,AccessManagementController,AccessLifecycleBulkService,UserLifecycleService,RoleLifecycleService,Tyro read adapter | T03–T05,T15,T18,T21,T22 |
| 04 Assignment | FR04 | BR04 | tickets.assigned_agent_id,agent_profiles,settings coordinator | AssignmentService::assign,auto-assignment query/job | T07,T08,T22 |
| 05 Concurrent assignment | FR05,NFR01 | BR04,BR15 | ticket lock/version,coordinator,receipts,activity | AssignmentService::claim,AssignmentService::assign | T08,T19 |
| 06 Conversation | FR06 | BR05,BR10 | ticket_messages,activity_entries | Message/NoteController,message policies,ConversationService::addMessage | T06,T09,T13 |
| 07 Attachments | FR07 | BR02,BR10 | ticket_attachments,composite message FK | AttachmentController,private storage,file policy | T13,T19,T22 |
| 08 Priority SLA | FR08,FR18 | BR06,BR07 | policies/targets,ticket SLA snapshot/intervals | BusinessCalendar,SlaService,TicketService::changePriority | T10,T11,T12 |
| 09 Business hours | FR09 | BR06,BR09 | calendar versions/windows/holidays,settings | Calendar admin,CalendarService::publish,SlaPolicyService::publish | T10,T11,T18 |
| 10 Escalation | FR10 | BR07,BR08 | ticket_escalations,activity,delivery intents | SLA scan/CheckTicketSla | T12,T14,T19,T23 |
| 11 Notifications | FR11 | BR08,BR14 | notification_deliveries,notifications | Laravel notifications,delivery workers,notification bar | T06,T14,T19,T23 |
| 12 Search/filter | FR12 | BR01,BR02 | ticket scope/composite/FULLTEXT indexes | TicketVisibilityQuery,filters,list controller | T03,T20,T24 |
| 13 CSV import | FR13 | BR02,BR03,BR12 | import_runs/import_rows,external references,receipts | Import controller,CSV reader,chunk jobs,TicketService::create | T16,T17,T19,T23 |
| 14 Dashboard | FR14 | BR13 | tickets,cycle-resolution activity snapshots | TicketAnalyticsService, AnalyticsController, role-specific Tyro views | T03,T20,T24,T25 |
| 15 Audit | FR15 | BR16 | activity_entries,receipts,bulk ledger | Business services,AuditController,public projection | T06,T15,T18,T19,T21 |
| 16 Database/performance | NFR01,NFR03,NFR05 | BR01,BR04,BR06,BR13 | FKs,uniques,checks,scoped indexes | Eloquent relations,query objects,bounded jobs | T08,T17,T19,T24 |
| 17 Automated testing | NFR06 | All BR | Isolated MySQL and fast test fixtures | Pest unit/feature/integration lanes | T01–T25 |
| Owner: record availability | FR16 | BR01,BR02 | is_active independent of workflow,actor fields | RecordLifecycleService::changeAvailability,policy/module controllers | T05,T07,T18,T22 |
| Owner: module soft delete | FR16 | BR01,BR10,BR16 | deleted_at,retained FKs,protected ledger exceptions | RecordLifecycleService::archive,RecordLifecycleService::restore,archive views | T05,T13,T18,T21 |
| Owner: bulk actions | FR17 | BR01,BR11 | bulk operations/items,receipts,activity | Concrete bulk controller,same single-record service methods | T15,T18,T19,T22 |
| Owner: analytical dashboards | FR14 | BR13 | Historical snapshots and scoped aggregates | Admin/agent/customer analytics views/drilldowns | T20,T24,T25 |
| Owner: Spatie data access | FR03 | BR01 | Prefixed access tables,user authorization_version | Permission resolver,policies,scoped queries,adapter | T03–T05,T21,T22 |
| Owner: service pattern | NFR05,D13,ADR06 | BR01–BR16 | Existing transaction/constraint/receipt design unchanged | app/Services domain services; thin controllers/jobs; shared import/bulk methods | T01,T03,T07–T12,T15,T17,T19,T22 |
| Cross-cutting security | NFR02 | BR01,BR02,BR10,BR14–BR16 | Private metadata,role lifecycle,receipts | Auth/2FA,policies,validation,safe errors | T03–T06,T13–T15,T21,T22 |
| Background/operations | NFR04,NFR07 | BR08,BR12,BR14,BR15 | Queues,delivery leases/import checkpoint | Scheduler,supervised workers,monitoring | T12,T14,T17,T19,T23 |
| Responsive Tyro UX | FR01,FR14,NFR05 | BR05,BR06,BR11,BR13 | No separate UI-only schema | Existing Tyro layouts/components,role pages | T25 |

## Mandatory engineering coverage map

| Engineering area | Primary specification | Acceptance/test evidence |
| --- | --- | --- |
| Code structure/business organization | architecture ADRs and placement; business BR03–BR13 | T01,T07–T12,T16,T19 |
| Validation | business BR02; browser/CSV contracts | T01,T09,T13,T15,T16,T21 |
| Eloquent relationships | database relationships/FKs | T01,T03,T13,T18,isolated migrations |
| Authorization | security registry/resolver/scopes | T03–T06,T15,T21,T22 |
| Jobs and queues | background job catalog | T12,T14,T17,T19,T23 |
| Events/listeners | background after-commit event matrix | T14,T19,T23 |
| Notifications | background recipients/channels and bar | T06,T14,T19 |
| Scheduler | background schedule/lock/recovery contract | T12,T17,T23 |
| Database transactions/concurrency | database transaction section; business BR04 | T02,T08,T12,T19,T21 |
| Error handling | business BR15; contracts response mappings | T01,T08,T13,T15–T17,T19,T21 |
| Storage | business BR10; security files; operations storage | T13,T17,T23 |
| Configuration | immutable BR09 and architecture config boundaries | T10,T11,T18,T21 |
| Testing | testing-strategy entire scenario catalog | T01–T25, actual results required in future work |
| Security | security threat model/control sections | T03–T06,T13–T15,T21,T22 |
| Performance/maintainability | operations query/cache/scale; architecture ADRs | T17,T20,T24,T25; measured benchmark later |

## Change-control requirement

Any approved requirement change updates its acceptance criterion, BR, schema/contracts/permission impact, test scenario and this row in one documentation change. Unmapped feature requests are not permission to guess behavior. No mapped test is claimed executed merely because it appears here. Owner final review and explicit implementation approval remain outstanding.
