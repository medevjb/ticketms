# Performance and operations

## Environment and measurable targets

Verified current connection: MySQL `ticketms`, server **8.0.33**. The approved application migrations and baseline permission/configuration seed have been applied; the implementation ledger records 30 migrations, the `admin`/`agent`/`customer` roles, 39 admin permissions, and zero users at the last read-only verification. Do not assume MySQL 8.4 or a deployment platform. Use current supported 8.0 syntax verified against this version; support policy/upgrade plan is a production operational decision. Laravel 13.39/Tyro 1.59 package APIs must be rechecked at implementation time.

Targets, not measurements: 1 million tickets, proportional messages/files/history, 50 concurrent active users, common ticket list/detail p95≤1s server response and SLA detection≤2min under healthy workers/scheduler. Benchmark report must name hardware, DB/cache/queue versions, dataset distributions, concurrency, test duration, p50/p95/p99/error rate and query counts. Browser/network/render latency measured separately; do not claim1s end-to-end.

## Query efficiency

- Scope by actor/ownership before filtering, searching, aggregation or cache access. Select required columns. Eager load customer/category/priority/assignee in bounded lists; paginate messages/history separately; avoid eager loading complete conversation/file/activity collections.
- Cursor pagination created_at/id descending, default 25/max 100. Queue uses safe preview projection. Large history uses occurred_at/id cursors; stable tie-breaker mandatory.
- Ticket number exact/prefix via unique/B-tree index. Keyword initially MySQL FULLTEXT(subject,description), with token-size/stopword limits made visible. No unrestricted `%term%` scans at million scale. For short/stopword terms return clear search limitation or number-prefix matching; alternative tokenizer/search infrastructure requires measured need and separate decision.
- EXPLAIN representative owner/status/date/SLA/agent workloads and aggregate queries. Verify examined rows/selectivity/order; an index is not evidence of use. Avoid DATE()/timezone functions on indexed UTC columns; convert Dhaka ranges to UTC before query.
- Warning/breach scanner reads persisted indexed projections on running tickets, then rechecks under lock. Finite chunks/cursor prevent long transactions/starvation. Recompute projections at domain transitions, not every page-row accessor.
- Assignment calculates counts in a grouped query for eligible candidates, not per-agent loop. Coordinator serializes assignment initially; instrument lock wait and throughput before replacing with finer locking. Never relax single-assignee invariant for speed.
- Historical analytics read resolution activity snapshots. Start with scoped aggregate SQL, not PHP loaded models. Large historical percentile calculations can be bounded/queued if response target fails; document approximation before adopting it. No full-table live chart for every dashboard request.

## Cache design

Database cache/shared locks initially. Request-local permission resolution prevents stale cross-request grants. P08 caches administrator analytics for at most 60 seconds using organization, role/version, date range and metric-version key components; agent/customer dashboards remain uncached to avoid stale assignment or ownership detail. Each response includes generated-at. Critical assignment, claim and authorization decisions never trust dashboard cache. Cache invalidation is bounded by TTL; this is acceptable for read-only aggregates and does not change workflow decisions.

Cache current published policy ID only with explicit invalidation; ticket calculations always pinned versions, so immutable calendar data can cache by version. Do not globally cache user-specific unread count or embed private content in shared cache. DB is source of truth for deduplication; cache locks alone cannot guarantee delivery or assignment.

## Process and storage topology

Initial single app deployment, MySQL/InnoDB, persistent private file volume, scheduler and supervised database workers. Allocate SLA/database-notification worker capacity independently from mail/import work. Horizontal app scale requires shared sessions/cache/files (private object storage/shared persistent storage) and common queue DB; never rely on node-local uploads across workers.

Proxy/PHP limits support ≤50 MiB plus multipart overhead for five ticket files and100 MiB CSV imports. Stream file uploads/downloads; do not read whole file into memory. Private storage failures are reported safely; referenced attachments retained. Temp input/error cleanup limits described in background-processing; retention is configurable and audited. Object lifecycle rules must not silently delete retained attachments.

## Logging and monitoring

Structured logs contain correlation/operation/ticket/import/job IDs, safe error code, duration and actor ID; never message/CSV payloads/passwords/tokens/2FA/environment secrets. Audit is business evidence, logs are operational diagnostics; neither replaces the other.

Monitor request p95/error rate, DB query duration/rows/lock wait/deadlocks/connections/disk, queue age per queue, failed jobs, delivery pending/failed/lease age, scheduler heartbeat, import progress, storage errors/capacity and mail failures. Alerts: scheduler heartbeat>2min, SLA queue oldest>60s, sustained5xx/DB errors, failed SLA scan, backup failure or unexpected absence, disk nearing capacity. Agree alert owner/channel at deployment; application notification bar is insufficient for total-app outage.

Keep error pages safe with correlation ID; no SQL/stack trace/debug payload in production. Ensure development Boost/Inertia tools unavailable publicly. Log rotation/access and security event review follow approved retention, not default indefinite disk growth.

## Deployment and migration gates (future)

The current completion target is the local Laravel/MySQL environment. The owner will handle production deployment and production testing after local acceptance. P10 plans local Docker initialization using the already installed Laravel Sail development dependency; keep the service set minimal (application, MySQL, queue worker, scheduler, local Vite workflow and private storage volume). Continue using MySQL/database-backed cache and queues initially; add Redis or other services only when measurements justify them. Do not introduce a paid hosting, monitoring, storage, or deployment platform as an application requirement.

Keep the application portable to a standard server: PHP 8.4 with Laravel-required extensions, MySQL 8.0-compatible InnoDB, a supported web server (Nginx or Apache), HTTPS termination, cron invoking Laravel's scheduler, and a supervised queue worker. Use normal Laravel environment configuration, `public/` as the web root, `storage/` and `bootstrap/cache/` write permissions, and persistent private file storage. Document exact server-specific commands only when a target is selected; avoid provider-specific runtime APIs and proprietary deployment dependencies.

1. Owner separately approves implementation and package installation. Verify installed PHP/Laravel/Tyro/Spatie versions, actual DB/schema and environment; no `composer setup` against existing data (it migrates/seeds side effects).
2. Establish version control/backup first; `.git` was absent during documentation inspection. Do not claim rollback via Git when unavailable.
3. Review additive migrations and vendor migration collisions. Dry-run SQL/rehearse isolated fresh and upgrade databases; production DB creation and migration credentials are explicit operations, never agent improvisation.
4. Run CI/testing gates, Spatie/Tyro compatibility and concurrency tests. The repository GitHub Actions workflow installs locked Composer/npm dependencies without running migrations, audits both lockfiles, builds frontend assets, and runs formatting/type/PHP test checks on SQLite. MySQL integration remains a separate opt-in disposable-schema lane. Dependency audits run against locked dependencies; do not apply blanket package updates.
5. Build assets if frontend changed; config/route/event/view caching per supported installed APIs; verify route collisions/auth aliases and deployment-only secrets. Restart workers to load new code/config; no stale long-running process.
6. Deploy application and migrations in compatible order; large backfills are restartable commands with bounded transactions. Feature flags can gate incomplete modules; never expose partial permission integration.
7. Health smoke: app, DB connectivity, login/2FA, role dashboard, private download, test notification delivery, queue and scheduler heartbeat. Use designated test data/accounts with authorization; no writes to live business records without explicit operational approval.
8. Rollback code only if old schema remains compatible; prefer forward-fix DB migration. Do not drop columns/tables or run migrate:fresh as rollback. Pause risky workers when version incompatibility exists.

No hosting provider or paid deployment service is selected, required, or provisioned here. Production deployment remains owner-managed and should use the server's standard PHP/web-server/process-supervision tools.

## Backup, recovery and retention

Proposed production objectives pending owner approval: RPO≤1hour, RTO≤4hours. Encrypted daily DB backups plus binlog/PITR retention sufficient for hourly RPO, encrypted private-file backups/versioning, and securely recoverable APP_KEY/transport configuration. Daily backup alone does not meet hourly RPO. Keep credentials/keys in separate controlled recovery store; restored DB without file/key recovery is incomplete.

Before go-live rehearse restoration into isolated environment and verify ticket counts, FKs, files/checksums, role/account restrictions, pinned calendars, import external IDs and delivery intents. Disable outbound mail/jobs during restore until reconciliation to avoid replaying historical notifications. Recovery runbook covers DB outage, disk loss, duplicate mail ambiguity and stalled import; publish actual measured restore time after rehearsal.

No hard deletion of business/audit records in initial release. Legal retention/anonymization policy must be approved before production, including deleted accounts, message/file bodies and backups. Soft deletion retains personal data. Proposed temp artifacts24h abandoned/30d terminal import files are not an approved general personal-data retention policy.

## Scale triggers

Measure before changing topology: sustained DB queue contention=>consider Redis; slow role-scoped fulltext=>evaluate search service; historical dashboard latency=>summary tables/queued reports; assignment lock bottleneck=>design finer allocation locks with equivalent concurrency tests. Add replicas only for stale-tolerant reads and document read-after-write behavior. No initial partitioning/sharding/microservices. Every change requires traceable requirement, observed bottleneck and rollback plan.
