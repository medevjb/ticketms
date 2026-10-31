# Support Ticket & SLA Management — documentation

Version: 1.5 · Prepared: 2026-11-01 · Status: P01–P09 application work is implemented and locally verified; a local Docker Compose development stack is available; owner acceptance and production deployment remain separate.

## Purpose and usage

Build a single-organization support system in the existing Laravel 13 application and Tyro dashboard. P01–P09 application scope is implemented. The live `ticketms` MySQL 8.0.33 database records 30 migrations and has baseline configuration, one account for each role, and three local demo tickets. Fresh local databases seed a test customer and agent; create the initial admin with `php artisan tyro-dashboard:createsuperuser`. The current ignored `.env` disables 2FA for this local test session.

Business logic uses the owner-approved **service pattern**: thin controllers call cohesive domain services shared by jobs, imports and bulk operations. See PRD D13 and architecture ADR06 for the mandatory boundaries and transaction responsibilities.

Read in the numbered order below for a complete understanding: source requirements → product scope → architecture → business rules → database and security → interfaces and background work → verification and operations → implementation and traceability. The original source brief was renamed to [02-initial-requirements.md](02-initial-requirements.md); its presentation has been standardized without changing its scope, business meaning, examples or design discretion.

The numbering is a recommended reading sequence, not a framework-mandated standard or the order in which code must be written. Business rules precede database details so the schema is read in the context of the behavior it must support.

## Recommended reading order

| Order | Document | Purpose |
| --- | --- | --- |
| 01 | [Documentation index](01-README.md) | Start here: reading order, evidence baseline and document authority |
| 02 | [Initial requirements](02-initial-requirements.md) | Source brief standardized for readability; original meaning preserved |
| 03 | [Product requirements](03-PRD.md) | Approved scope, acceptance criteria and decision register |
| 04 | [Application architecture](04-architecture.md) | System boundaries, Laravel structure and Tyro/Spatie decisions |
| 05 | [Business logic](05-business-logic.md) | Workflows, SLA calculations, transitions and edge cases |
| 06 | [Database design](06-database-design.md) | Schema, relationships, constraints, indexes and transactions |
| 07 | [Security and authorization](07-security-and-authorization.md) | Permissions, ownership, authentication and private data |
| 08 | [API and integration contracts](08-api-and-integration-contracts.md) | Browser commands, CSV and external boundaries |
| 09 | [Background processing](09-background-processing.md) | Jobs, events, notifications, schedules and recovery |
| 10 | [Testing strategy](10-testing-strategy.md) | Behavioral, security, concurrency and regression verification |
| 11 | [Performance and operations](11-performance-and-operations.md) | Query efficiency, observability, deployment and backups |
| 12 | [Implementation plan](12-implementation-plan.md) | Delivery phases, dependencies and acceptance gates |
| 13 | [Traceability matrix](13-traceability-matrix.md) | Requirements mapped to rules, data, components and tests |
| 14 | [AI agent guidelines](14-ai-agent-guidelines.md) | Mandatory guardrails; agents also read this before any task |

## Reading priorities

- **Product review:** 01–03, then 05 for exact business behavior.
- **Architecture review:** 04–07 are the core design baseline; read 08–09 for integration and asynchronous consistency, then 10–11 for quality and operational constraints.
- **Implementation planning:** 12 defines build order; 13 verifies requirement coverage. Neither is permission to begin implementation.
- **AI coding agents:** read 14 immediately after this index, then 03–07 and the task-specific contracts, background rules and tests. The guidelines are numbered last as a reference, but apply before every code change.

For every implementation task, consult its traceability row and all linked rules, schema, permissions and test scenarios. Update affected documents together when an approved decision changes.

## Evidence baseline

Verified by source/configuration inspection and read-only tooling on 2026-10-28/29:

- Installed Laravel 13.39.0, Tyro Dashboard 1.59.0, Inertia Laravel 3.5.2, Pest 5.3.4. `composer.json` specifies compatible ranges rather than these exact patch releases. Recheck installed versions before implementation.
- Existing starter and Tyro integration remain in place. P03–P08 add authenticated ticket workflows, assignment/claim, conversations/files, delivery workers, imports and role-scoped analytics within the existing Tyro shell. P09 adds local security, dependency and CI hardening; no production deployment testing is part of local completion.
- Tyro package routes/configuration and many published Blade templates exist. The app exposes a read-only Spatie-to-Tyro role adapter, and duplicate management surfaces are blocked. Feature tests cover authentication/authorization contracts; interactive browser and responsive behavior still need owner review.
- Initially inspected SQLite contained only framework tables. MySQL was then configured but returned error 1049 for missing `ticketms`. After the owner created it, read-only inspection verified MySQL **8.0.33** and an empty `ticketms` schema. The authorized implementation then applied 30 migrations and seeded the baseline configuration. At validation, `users=0`, `access_roles=3` (`admin`, `agent`, `customer`), Tyro `roles=0`, `categories=1`, `priorities=4`, `calendar_versions=1`, and `sla_policies=1`.
- Database queues, failed-job and batch configuration exist. `composer run dev` starts the local server, Vite, Reverb, logs, a listener for every application queue and the Laravel scheduler. Queue `after_commit` currently defaults to false; durable notification intents and scheduled recovery provide the required handoff. `Dockerfile` and `compose.yaml` provide a local-only app, MySQL, queue, scheduler and Reverb stack.
- Feature tests use in-memory SQLite. Latest full suite passed **114 tests and 621 assertions**; the opt-in MySQL suite passed **31 tests and 104 assertions** against a disposable schema.
- The original source brief, now `docs/02-initial-requirements.md`, was the only pre-existing file under docs. `.ai/rules` and `.git` were absent during inspection; no repository history or clean working-tree claim can be made.
- Spatie Laravel Permission **8.3.2** is installed and configured with prefixed tables. The User model exposes read-only Tyro role/privilege adapters, and Tyro's duplicate API and role-management routes are disabled. The application permission registry and scoped ticket authorization are implemented; app-owned user/role lifecycle and permission-inspection pages are available under Access; role creation, membership editing and permission-grant editing remain out of scope.
- Database validation performed: schema constraint, rollback/reapplication, seeder idempotency, ticket workflow, analytics bucketing, simultaneous assignment and last-admin tests. MySQL integration tests run against a random disposable schema, not the working `ticketms` database.

## Local handoff

1. Confirm `.env` points to the existing `ticketms` database. It already has its migrations and baseline configuration; do not reset or wipe it.
2. The working database already has an admin account. Use the credentials you previously set up. On a fresh local database, run `php artisan ticketing:make-admin` and enter the administrator name, email and password interactively.
3. Local/testing databases can seed `agent@agent.com` and `user@user.com` using `php artisan db:seed --class=Database\Seeders\TicketingTestAccountsSeeder`. Both test accounts use the local-only password `password`; the seeder does nothing in production.
4. Run `composer run dev` to start the app. The worker listens on `imports`, `sla`, `notifications-database`, `notifications-mail`, and `default`; Reverb and the scheduler run alongside it. Stop the processes with Ctrl+C. The root [README](../README.md) also documents a Docker Compose local setup.
5. Two-factor authentication is disabled by `TICKETING_TWO_FACTOR_ENABLED=false` in the current local `.env`; the PHPUnit environment keeps the security policy enabled for its tests. Review ticket creation, assignment, replies, attachments, imports, bulk actions, notifications and role-scoped dashboards in the browser. Email uses the configured local mailer.

Browser/responsive acceptance and role-specific end-to-end use require your local hands-on review. Record any issues and run `php artisan test --compact` after fixes. See [testing strategy](10-testing-strategy.md) for scenarios and current automated coverage.

## Decision authority

1. Owner-approved requirements and later corrections override this baseline.
2. PRD decision register records discussion approvals and explicitly proposed design details.
3. Business logic defines calculations; database design implements their integrity requirements; security defines access scope. Update all affected documents together when a decision changes.
4. Database implementation was explicitly approved. The remaining product features still require their own approved task scope.

## Validation of this documentation

The implementation plan contains both the original documentation review and the current database implementation validation ledger. It distinguishes completed schema tests from unexecuted product acceptance, authorization, performance and operational release gates.
