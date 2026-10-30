# Security and authorization

## Authority and threat model

Spatie stores roles/permissions; Laravel policies enforce record scope. See architecture ADR01. Main threats: cross-customer access, claim/reassignment races, internal-note leakage, malicious uploads/CSV, stale permissions after revocation, disabled-account sessions, generic Tyro routes bypassing app rules and duplicate background side effects. Single organization does not remove ownership boundaries.

Under the service pattern (architecture ADR06), public business-service mutations enforce policy checks for their explicit actor, including when invoked by jobs, imports or bulk operations. Controllers may authorize early for feedback, but services must not assume an HTTP request already authorized the operation. EffectivePermissionService resolves grants; it does not replace resource policies or service-owned state/integrity checks.

## Identity controls

- Session `web` guard; verified email before customer ticket operations. Administrators provision staff; public registration always creates customer and rejects supplied role/permission/active flags.
- Staff require confirmed 2FA using verified Tyro Login integration; encrypt secret/recovery values, hide from serialization/logging, rate-limit challenges and recovery, audit staff resets. No skip or remembered bypass until reviewed.
- Active-account middleware rejects disabled/deleted accounts on every request, including API/package routes; invalidate sessions and remember tokens on disable, password/security reset and archive. Recheck account state in queued administrative work.
- Use Laravel password hashing, reset-token flow, session regeneration on login and logout invalidation. Production password defaults must be verified across package-specific registration/reset validators rather than assumed inherited.
- Implemented starting limits: Tyro Login locks after 5 failed attempts per IP for 15 minutes; Laravel's password broker throttles reset-token generation to 60 seconds; app routes limit ticket creation to 10/minute per actor, replies to 30/minute per actor, imports to 3/hour per administrator, and ticket/import-error downloads to 60/minute per actor. Avoid exposing account existence in recovery responses. Review shared-cache behavior, trusted-proxy configuration and these thresholds under production load. Additional independent limits for verification resend and normalized-email-plus-IP login attempts remain deployment/security review items.
- Protect last usable admin and self-disable/archive; users cannot grant themselves roles or reset their own staff 2FA requirement. Sensitive role/security changes require recent password/2FA confirmation and are audited.

## Permission registry

Code-owned permission names are distinct from policy methods. In particular do not name permission `view`, `update`, `delete`, `resolve`, etc. Spatie's global Gate callback must not turn a permission into unconditional model authorization. Policies use EffectivePermissionService plus scope/state. No wildcard grants or unconditional admin bypass.

| Permissions | Default roles | Additional constraints |
| --- | --- | --- |
| `tickets.create`, `tickets.view`, `tickets.search` | admin,agent,customer | Customer owner bound to self; agent cannot create for another customer |
| `tickets.update_content` | admin,customer | Customer own Open ticket before first staff public reply; description/subject only; audit safe metadata without duplicating text |
| `tickets.start`, `tickets.wait`, `tickets.resume`, `tickets.resolve` | admin,agent | Agent assigned; graph/state/availability guards |
| `tickets.close`, `tickets.reopen` | admin,customer | Customer owns; Resolved only |
| `tickets.assign`, `tickets.change_priority`, `tickets.change_category` | admin; priority/category also agent | Assignment admin only; agent priority/category assigned only |
| `tickets.claim` | admin,agent | Actor must be effective agent with eligible profile for own claim; admin uses assignment for others |
| `tickets.reply_public`, `tickets.attach` | admin,agent,customer | Same conversation visibility and state guards |
| `tickets.note_internal` | admin,agent | Assigned agent/admin only; not queue preview |
| `tickets.audit_view` | admin,agent,customer | Public-filtered own/customer audit; assigned agent sees staff history; admin all |
| `tickets.manage_availability`, `tickets.archive`, `tickets.restore`, `tickets.bulk` | admin | Closed-only archive; all per-item invariants |
| `messages.moderate`, `attachments.moderate` | admin | Reason required; private content not copied to public history |
| `users.manage`, `agents.manage`, `roles.manage` | admin | Last-admin/self guards; custom role lifecycle only |
| `categories.manage`, `priorities.manage`, `sla.manage`, `calendars.manage` | admin | Published-version immutability; current pointer protection |
| `imports.manage`, `imports.download_errors` | admin | Private scoped files; run state guards |
| `analytics.view` | all | Admin all; agent own/queue preview; customer own |
| `audit.view_all`, `operations.view`, `operations.retry` | admin | No editing/deleting audit; safe operation-specific retry |
| `notifications.view`, `notifications.mark_read` | all | Own notifiable identity, and linked ticket visibility |

Dynamic custom roles can reuse these permissions but not redefine the actor scope. `admin` and `agent` capability classes are explicit protected system roles; a custom role does not become staff by receiving a permission. Initial role memberships may coexist; admin scope takes precedence, otherwise agent scope, otherwise customer ownership. A customer can create an owned ticket even if also agent, but cannot gain access to other owners' tickets without assigned/admin scope.

## Effective permissions and role lifecycle

Spatie is the only grant store. Application Role extends installed Spatie Role with `is_active`, archive metadata and lifecycle support. Package behavior with custom soft-deleted models/cache must be proven in compatibility tests; adding a column alone is insufficient.

EffectivePermissionService algorithm:

1. Reject inactive/deleted user.
2. Load role membership through Spatie-compatible pivots, filter active/nondeleted roles explicitly, and load their code-defined permission names for guard `web`.
3. Check exact permission names. No direct user grants, Tyro grants or user-supplied permission names create new authority.
4. Policy combines permission with actor class, resource visibility and action conditions. Query layer applies the same visibility predicate before search/aggregation.

Configure Spatie's default Gate hook off only through its supported setting, then register a narrowly scoped permission Gate callback delegating to this resolver for registry names. It must return null for policy verbs/unknown names, allowing policy evaluation. Use normal Gate/policies at endpoints. Do not modify vendor package methods. Adapters calling `hasRole` must also honor role lifecycle: alias/delegate Spatie methods only with version-verified signatures, or constrain calls to active role IDs. Direct raw package role checks must not decide protected app access.

Cache effective results request-locally initially, not for minutes across requests. Role/membership edits use package APIs, clear PermissionRegistrar caches and in-memory relations, bump affected users' authorization_version and invalidate active sessions for critical revocation. Role changes invalidate all holders. Per-record execution reloads effective identity where a concurrent role edit matters. Disable/archive a custom role removes effective permissions immediately; archive detaches memberships with audit; restore never silently restores old memberships. Guard role/permission name uniqueness across archived records.

## Read/data access rules

- TicketVisibilityQuery: admin all with explicit archive mode; customer `customer_id=actor.id`; agent `assigned_agent_id=actor.id` for full access plus separate unassigned preview query. Never fetch all and filter in PHP.
- Queue claim rechecks actor and ticket under lock; preview is not full view permission. Unassigned private conversation is not available before claim.
- `TicketPolicy` governs full ticket view, each mutation, archive/restore; message/file policies require both parent permission and visibility. Nested binding checks the child's ticket ID.
- Customer audit queries require public visibility and safe fields. Internal-note IDs, filenames, counts and diff values are not returned. Administrative audit remains internal.
- Analytics and bulk endpoints reuse query scope. Cache keys include actor/scope/authorization_version/filters; shared customer or agent cache without scope is prohibited. Notification list/unread-count/read-all queries join or constrain against current authorized ticket scope, rather than loading every notification and calling one policy query per row.
- Archive routes use explicit `withTrashed()` only after authorization. Public numeric IDs are not secrets; unguessable numbers never replace policies.

## File and content security

Private disk for tickets/imports/errors; never Tyro's public media library. Validate extension and detected MIME against explicit map, byte size and count; reject SVG/HTML/executables/archives initially. Text MIME ambiguity requires explicit tests; CSV and TXT are served as downloads, never rendered as HTML. Generated opaque path, sanitized original display name, forced `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, no directory traversal or arbitrary disk/path input.

Every download reauthorizes parent/current visibility and lifecycle. Prefer app-streamed downloads initially; signed storage URLs, if introduced, require short expiry and acknowledge inability to revoke issued URLs immediately. Reject external URL attachment/import fetching (SSRF surface). Optional malware scanning is a production security decision, not implemented or promised; quarantine until clean if required. Keep storage outside executable web roots.

P05 implements private local-disk staging, opaque `ticket-files/` paths, explicit extension-to-detected-MIME checks, the approved five-file/10 MiB limits, sanitized download names, forced downloads with `nosniff`, and parent-scoped access checks. Customer message queries and the ticket-level attachment view both exclude internal-message attachment metadata. Focused tests cover internal-note/file isolation, assigned-agent download, public-message redaction, and staged upload flow; spoofing, storage outage, and the full T13 denial matrix remain hardening coverage.

Plain text only and escaped Blade/mail output. No raw `{!! !!}` for ticket content. Notification payloads exclude message bodies and attachment paths. Error reports neutralize CSV cells beginning `=`, `+`, `-`, `@`, tab or carriage return. Do not log raw CSV/body/passwords/tokens/2FA codes. Redact mail transport secrets, environment files and storage credentials from diagnostics.

## Administrative surface

Native Tyro role/user/resource/API handlers are not assumed safe for Spatie or soft deletion. Feature-disable or deny incompatible routes server-side and verify with route registry and direct URL tests. Build app-owned management screens inside Tyro. Preserve harmless compatible profile functions, with explicit denial of hard account deletion. Package health/log/settings/checkpoint/media functions need admin-only authorization and separate permissions where retained; disable unnecessary production functions. Never expose raw `.env` editing to agent/customer.

HTTPS, secure/HttpOnly/session cookies, SameSite policy, trusted proxy/host configuration, CSRF and output escaping are release checks. Production debug off; devtools/Boost routes unavailable publicly. Database account least privilege; runtime cannot administer/drop schemas. Deployment/migration credentials separate. APP_KEY and mail/storage secrets in environment/secret store via config, not source or audit.

## Required evidence

Testing scenarios T03–T06, T12–T15 and T18–T22 cover ownership, internal content, disabled roles/users, bulk mixed scope, file access and package bypasses. Manual role/UI review does not replace HTTP tests. Concurrency and cache-revocation tests must run against installed Spatie version and isolated MySQL. No security certification, penetration test or package compatibility test was performed for these documents.
