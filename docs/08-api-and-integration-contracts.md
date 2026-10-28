# API and integration contracts

## Scope

Initial product is authenticated Tyro Blade web UI, not a public REST API. This document defines browser commands, CSV and outbound mail/storage boundaries for implementers. No API specification, Postman workspace or external mock was created. If public integrations are later requested, agree versioning/auth/idempotency contract first and use the Postman engineering workflow; do not expose all Eloquent fields by default.

Tyro currently registers package APIs (including login/user creation and token-protected administration). Those are package surfaces, not approved ticket APIs. Inventory and disable unused APIs using supported Tyro configuration, and test direct access. Native role/user writes conflicting with Spatie or soft deletion must be disabled/replaced, not merely hidden.

## Browser route conventions

Proposed paths/names; implement after checking conflicts with actual route registry. Prefix app endpoints `/support` and names `support.*` to avoid Tyro `dashboard` route collisions; admin configuration `/support/admin`. Existing dashboard index can redirect by effective role to these Tyro-layout pages. Keep original home and package-compatible auth routes.

All routes below require active authenticated verified identity; staff require 2FA. Mutations require CSRF and policy. Form redirects use named Laravel routes; any future Inertia callers use Wayfinder.

| Method/path | Name suffix | Request / response |
| --- | --- | --- |
| GET `/support` | dashboard | Role-scoped analytics filters/date range; Blade page |
| GET `/support/tickets` | tickets.index | Search/filter/sort/cursor; authorized list |
| GET `/support/queue` | queue.index | Agent queue preview, no private conversation |
| GET `/support/tickets/create` | tickets.create | Options + SLA notice; form operation UUID |
| POST `/support/tickets` | tickets.store | subject,description,category_id,priority_id,attachments[],operation_key; admin optional customer_id |
| GET `/support/tickets/{ticket}` | tickets.show | Policy-authorized detail/messages/activity cursors |
| PATCH `/support/tickets/{ticket}` | tickets.update | Only editable content fields + expected_version; no status/owner/SLA mass assignment |
| POST `/support/tickets/{ticket}/claim` | tickets.claim | operation_key,expected_version; conflict if no longer unassigned |
| POST `/support/tickets/{ticket}/assignment` | tickets.assignment | assigned_agent_id nullable,reason,expected_version,operation_key; admin |
| POST `/support/tickets/{ticket}/transition` | tickets.transition | target_status,reason/resolution_summary,expected_version,operation_key; explicit graph |
| POST `/support/tickets/{ticket}/reopen` | tickets.reopen | reason,optional public message,expected_version,operation_key; atomic reopen+reply |
| POST `/support/tickets/{ticket}/priority` | tickets.priority | priority_id,reason,expected_version,operation_key |
| POST `/support/tickets/{ticket}/category` | tickets.category | category_id,reason,expected_version,operation_key |
| POST `/support/tickets/{ticket}/messages` | messages.store | public body/files,operation_key,expected_version |
| POST `/support/tickets/{ticket}/notes` | notes.store | internal body/files,operation_key,expected_version; assigned staff only |
| GET `/support/attachments/{attachment}/download` | attachments.download | Policy + parent visibility + availability; streamed attachment |
| POST `/support/admin/{module}/bulk` | admin.bulk | Explicit module/action enum, operation_key, IDs,expected_versions,parameters,reason |
| POST `/support/admin/tickets/{ticket}/availability` | admin.tickets.availability | is_active boolean,reason,expected_version |
| DELETE `/support/admin/tickets/{ticket}` | admin.tickets.archive | Closed only; soft-delete; reason,expected_version |
| POST `/support/admin/tickets/{ticket}/restore` | admin.tickets.restore | Explicit archive lookup/admin; restore disabled Closed |
| GET/POST/PATCH `/support/admin/{managed-module}` | admin module-specific | Separate concrete controllers/policies, never unconstrained generic model name resolver |
| POST `/support/admin/calendars/{calendar}/publish` | admin.calendars.publish | Validate draft; make calendar immutable; policy activation is separate |
| POST `/support/admin/sla-policies/{policy}/publish` | admin.sla.publish | Full target validation and current pointer swap |
| POST `/support/admin/imports` | admin.imports.store | source,file,operation_key; queued run with status page redirect |
| GET `/support/admin/imports/{run}` | admin.imports.show | Safe counters/status/row error pagination |
| POST `/support/admin/imports/{run}/{command}` | admin.imports.command | Allowlisted pause/resume/cancel/retry; permission/state recheck |
| GET `/support/admin/imports/{run}/errors` | admin.imports.errors | Authorized private escaped CSV download |
| GET `/support/notifications` | notifications.index | Own visible paginated entries + unread count; Blade partial or safe JSON |
| POST `/support/notifications/read` | notifications.read | selected notification UUIDs ≤100, or explicit own read-all scope; never notifiable_id input |

Do not expose slug `{module}` as arbitrary PHP class/path. Register concrete module allowlist and resolve policy/action explicitly. All module archive/restore routes follow same protection as ticket examples.

## Common command contract

Controllers translate validated commands into injected domain service instance methods (architecture ADR06), not direct model mutations or an Actions layer. TicketService handles creation/transitions, AssignmentService handles claim/assignment, and ConversationService handles messages; import/bulk callers reuse these same methods with explicit actor context. Services return domain results/exceptions; controllers alone format redirects/JSON/HTTP errors.

- `operation_key`: UUID issued/validated for actor+action, persisted uniquely at operation/event boundary. Same key/same payload returns existing result; same key/different payload returns409. Persist canonical payload hash in activity/operation metadata to enforce this, without secret-bearing full payload storage.
- `expected_version`: positive integer for existing tickets; compare under transaction lock; stale version=>409 safe refresh message. Claim also checks assignee null and state.
- Successful Blade writes redirect with success flash; HTTP POST-redirect-GET status303 preferred. Unexpected storage/queue failures never report ticket success before commit. Notification asynchronous delivery failure does not reverse committed ticket.
- Explicitly allowlisted JSON endpoints use `{data:...,meta:{correlation_id,...}}`; errors `{message,code,errors?,correlation_id}` without stack/SQL/private data. Lists include cursor,next/previous links and applied filters; no full unbounded collection.
- HTTP semantics: unauthenticated browser redirect login; JSON401; forbidden403 or hidden404; validation422 JSON/redirect with field errors Blade; conflict409; CSRF419; throttled429; unavailable503; unexpected500. Do not leak existence by choosing different errors for private/internal records.
- Date filters are local Dhaka YYYY-MM-DD converted to UTC bounds; range≤366 days initially. Page size25 default, max 100; cursor sort created_at/id descending. Search≤200 chars, status/priority/category IDs validated, sort mapped to known fields; no raw query fragments.

## CSV contract

File≤100 MiB, UTF-8 with optional BOM, comma-delimited, header required, CSV quoting/multiline supported. Required exact columns:

```csv
external_id,customer_email,subject,description,priority,category
CRM-1001,customer@example.test,Login problem,Cannot sign in,high,account
```

`source` supplied once at upload; normalize trimmed source/external ID case-sensitively (binary/case-sensitive unique index), no arbitrary status/created_at/assignee columns. Email normalized consistently with registration. Priority/category enabled slugs; customers must exist, active and verified. Missing/extra/duplicate headers fail before processing. Formula-safe output report columns `row_number,external_id,field,error_code,message`; raw ticket description omitted. Duplicate `(source,external_id)` counts duplicate without update; archived tickets remain duplicates. See BR12 for checkpoint/partial success.

## Notifications and storage integrations

Laravel Notifications: mandatory database+mail channels, queue mapping/retry in background-processing. Mail provider not selected. Require configured sender/domain, authenticated SMTP/API transport, TLS and provider limits; no inbound reply ingestion. Mail failure isolated from transaction. External transport exactly-once is not guaranteed.

Laravel Storage: private local initially; config disk abstraction allows future private object storage without changing ownership rules. Paths generated by server; stored checksum/size/MIME. No public media-library reuse, arbitrary URL fetch, or client-supplied disk/path. Storage outage maps to safe failure/compensation; stale source file marks import failed with recoverable operator steps.

Spatie/Tyro integration contract is architecture ADR01 and security resolver. This is a required compatibility spike, not a tested off-the-shelf combination. Any future public API/mobile client must preserve the same services/policies and introduce versioned explicit resource serializers with private-note omission tests.
