# Rules for future AI coding agents

These are project-specific implementation instructions to read alongside repository AGENTS.md and applicable skills. The database foundation is implemented; product workflows, authorization integration and user interfaces are still planned. Follow the scope of the owner's current implementation authorization and do not infer approval for unrelated feature work.

## Before changing anything

1. Read docs index/PRD decision register and the task's BR/schema/security/background/T/traceability sections. Clarify a real unresolved business conflict; do not silently invent behavior.
2. Inspect current files and installed versions again; prior snapshots are not guaranteed current. Confirm actual MySQL test isolation. Existing app is Laravel 13 with Tyro; preserve working integration.
3. Read `.ai/rules/index.md` and matching/keyword rules if directory now exists. It did not exist during documentation. Apply Laravel, Tyro, MySQL, testing and other relevant skills. Use Boost docs/schema tools as directed by AGENTS.md.
4. Verify scope/approval. Do not install packages, migrate, seed, deploy, send mail, start workers or alter credentials merely because a plan names them. The documentation task did none of these.

## Mandatory code and architecture rules

- Extend app code/config/published views; never edit vendor. Use conventional Laravel generators when creating implementation files; typed parameters/returns, constructor injection, casts and relationships, sibling naming/style.
- Use the owner-approved service pattern in architecture ADR06: thin controllers call cohesive injected services under `app/Services/`; services own business rules, domain mutation and transaction boundaries. Form Requests validate HTTP and services reuse domain rules for import/jobs; policies authorize every resource operation, including service calls outside HTTP. No parallel `app/Actions/` layer, generic BaseService/repository hierarchy or mechanical service per model. Collaborating services participate in the outer operation transaction; do not create independent commits inside atomic workflows.
- Spatie is the only permission store. Persistence is installed as Spatie 8.3.2 using prefixed `access_*` tables. Do not combine HasTyroRoles and HasRoles or map Spatie to Tyro role tables. The current Tyro adapter is read-only; verify it with P01 auth/route tests and never override `can()` to bypass record policies.
- Permission ability names differ from policy verbs. No unconditional admin Gate bypass. Role grants alone never remove ownership/assignment checks or last-admin/state guards. Active/deleted users/roles respected on every route/job/read.
- Ticket lifecycle status, administrative is_active and deleted_at are separate. Follow module matrix. Soft delete is not privacy erasure; never delete audit/identity ledgers or cascade archive live ticket obligations.
- Database constraints support validation; unique number/import/escalation/receipt keys and FKs are mandatory. Transaction includes state/message/SLA/history/delivery intent. Consistent lock order; no SMTP/network/file write in retried transaction.
- SLA is pinned versioned calendar/policy + original active business seconds less exclusions. Waiting/resolved stops consume no budget; customer reply resumes; calendar edits new-only; priority change no reset; Closed final. No duplicated addHours calculation in controllers, jobs or views.
- Reuse single-record service methods in bulk and import. Each record authorized and state checked at execution, bounded limits/partial results explicit. No raw bulk updates bypassing activity/permission/interval handling.
- Private attachments/import errors only; content/MIME/size/count checks; authorize every download including parent visibility/availability. Internal content must never be loaded into customer response, mail, audit projection or cache.
- Notifications use Laravel mail/database channels and safe payloads, queue email, durable intent/recovery/idempotency; exactly-once SMTP is not guaranteed. Jobs reload identifiers and authorization; finite chunk work and bounded retry.
- Use named Laravel routes; if touching frontend/backend route calls in React use Wayfinder. Ticket pages extend Tyro Blade, preserve existing Inertia welcome page. No new dashboard framework or UI kit.
- Environment values only in config; business settings versioned in DB; secrets never in source/output/audit. Error responses safe, logs correlated. Avoid broad catches returning false success.
- Scope query before paginate/search/aggregate/cache; eager load bounded relations, cursor tie-breaker, allowlisted sort. Index changes justified by actual query plans. No premature search/Redis/microservice dependency.

## Tests and review

Implement critical behavioral T scenarios alongside code. Confirm test database isolation before RefreshDatabase or concurrency work; MySQL lock tests require independent connections and barriers. Fakes for external boundaries, not for domain services being tested. Preserve existing tests; do not write assertions that merely repeat configuration declarations instead of behavior.

Run narrow tests, required Pint for PHP, relevant static analysis and frontend checks. Report commands actually executed with outcomes; skipped/blocked tests are explicit. After focused tests pass ask owner to run full suite as project rules require. Do not claim a document scenario, code inspection or SQLite test proves MySQL concurrency.

Before handoff review authorization/data leaks, transaction failure/retry, constraints, pinned SLA arithmetic, lifecycle/bulk exceptions and Tyro compatibility. Update all affected docs/traceability when owner-approved behavior changes. Report files, reasoning, evidence and material remaining risks. No feature implementation follows documentation approval automatically; wait for explicit implementation authorization.
