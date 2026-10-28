# Support Ticket & SLA Management — documentation

Version: 1.0 · Prepared: 2026-10-29 · Status: proposed implementation baseline, awaiting final owner approval.

## Purpose and usage

Build a single-organization support system in the existing Laravel 13 application and Tyro dashboard. These documents specify future behavior; they do not claim that features exist. No implementation, dependency installation, migrations, or runtime acceptance tests were performed while preparing this suite.

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
- App code contains the starter User, controller, provider, Inertia middleware and welcome page. No ticket-domain implementation exists.
- Tyro package routes/configuration and many published Blade templates exist. User currently lacks Tyro role integration and email-verification enforcement. End-to-end authentication/dashboard readiness is not proven.
- Initially inspected SQLite contained only framework tables. MySQL was then configured but returned error 1049 for missing `ticketms`. After the owner created it, a fresh read-only query verified database `ticketms`, server **8.0.33**, and schema inspection returned no tables. Connection is now verified; application/package schema initialization is future work.
- Database queues, failed-job and batch configuration exist. Queue `after_commit` currently defaults to false. No app scheduled tasks are registered.
- Only starter example tests exist. Feature tests are configured for in-memory SQLite. Production lock/concurrency tests must use isolated MySQL.
- The original source brief, now `docs/02-initial-requirements.md`, was the only pre-existing file under docs. `.ai/rules` and `.git` were absent during inspection; no repository history or clean working-tree claim can be made.
- Spatie Laravel Permission is requested but is not installed. No package changes are authorized by this documentation task.

## Decision authority

1. Owner-approved requirements and later corrections override this baseline.
2. PRD decision register records discussion approvals and explicitly proposed design details.
3. Business logic defines calculations; database design implements their integrity requirements; security defines access scope. Update all affected documents together when a decision changes.
4. Do not infer approval to implement from approval to document. Stop after document review until explicit implementation approval.

## Validation of this documentation

The implementation plan contains a documentation validation ledger. It distinguishes performed link/coverage/consistency review from unexecuted application tests, migrations, benchmarks and compatibility spikes. Release gates remain future work.
