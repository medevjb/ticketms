# TicketMS

TicketMS is a single-organization support desk built with Laravel 13 and the Tyro dashboard. It gives administrators, agents, and customers focused views of the same ticket lifecycle, with business-hours SLAs, auditable conversations, background delivery, and private realtime updates.

## What’s included

| Area                 | Implemented capabilities                                                                                                                             |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| Ticket operations    | Ticket creation and numbering, filters, assignment and claim, status transitions, bulk actions, attachments, and searchable ticket history           |
| Admin workspace      | Users and agents, categories, priorities, business calendar, SLA policy, imports, analytics, timezone settings, notifications, and access management |
| Agent workspace      | Assigned tickets, unassigned queue, claim and assignment controls, ticket replies, internal notes, SLA visibility, and workload analytics            |
| Customer portal      | Ticket creation and tracking, conversation replies, profile and attachments, notifications, and clear response-time expectations                     |
| Automatic assignment | Least-workload selection with a round-robin-style fairness tie-break, concurrency locks, and an explicit unassigned state when no agent is eligible  |
| SLA engine           | Business-calendar arithmetic, warning and breach thresholds, pause/resume intervals, historical breach tracking, and ticket-level SLA snapshots      |
| Realtime             | Laravel Reverb and Echo deliver ticket updates and public replies over authorized private channels; internal notes stay private                      |
| User API             | Versioned `/api/v1` API for administrator-managed customer accounts, authenticated with short-lived Tyro/Sanctum bearer tokens                       |
| Background work      | Database queues, retryable notification delivery, SLA scans, import recovery, and Laravel’s scheduler                                                |
| CI                   | GitHub Actions checks dependencies, builds assets, runs formatting/static checks and tests; Dependabot checks Composer, npm, and Actions weekly      |
| Docker               | Local Compose stack for the web app, MySQL, queue worker, scheduler, and Reverb                                                                      |

The GitHub workflow validates changes; it does **not** deploy the application. Production deployment and service supervision must be configured for the target host.

## Requirements

- PHP 8.4 recommended (the Composer constraint allows PHP 8.3+)
- Composer 2
- Node.js 22 and npm
- MySQL 8.0+
- Laravel Herd is convenient for local HTTPS and Reverb TLS; Docker Compose is also available for local development

## Local setup with Herd

The configured Herd domain is `https://ticketms.test/`.

1. Install PHP and JavaScript dependencies and create the local environment file:

    ```bash
    composer install
    npm ci
    cp .env.example .env
    php artisan key:generate
    ```

    If `.env` already exists, keep it and check the values below rather than overwriting it.

2. Configure `.env` for your local MySQL database and secure Herd domain:

    ```dotenv
    APP_URL=https://ticketms.test
    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=ticketms
    DB_USERNAME=your_local_mysql_user
    DB_PASSWORD=your_local_mysql_password
    BROADCAST_CONNECTION=reverb
    REVERB_HOST=ticketms.test
    REVERB_PORT=8080
    REVERB_SCHEME=https
    REVERB_ALLOWED_ORIGINS=ticketms.test
    VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
    VITE_REVERB_HOST="${REVERB_HOST}"
    VITE_REVERB_PORT="${REVERB_PORT}"
    VITE_REVERB_SCHEME="${REVERB_SCHEME}"
    ```

    Keep the `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET` values generated for your local install. Vite embeds its `VITE_REVERB_*` values in the frontend bundle, so restart Vite after changing them.

3. Create the tables and baseline ticket configuration, then make an administrator:

    ```bash
    php artisan migrate --seed
    php artisan tyro-dashboard:createsuperuser
    ```

    The command also has the alias `ticketing:make-admin`. The local/testing seeder creates `user@user.com` and `agent@agent.com` with password `password`; these are demo accounts only. Do not use those credentials in production.

4. Start the local development processes:

    ```bash
    composer run dev
    ```

    This project’s Laravel dev process starts the local server, Vite, Reverb, the queue listener, the scheduler, and logs. Open `https://ticketms.test/` through Herd. If port 5173 is already held by another Vite process, stop the duplicate process before restarting this one.

The individual services can also be run separately. For a secure Herd Reverb endpoint, use:

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080 --hostname=ticketms.test
php artisan queue:work database --queue=imports,sla,notifications-database,notifications-mail,default --sleep=1 --tries=3 --timeout=120
php artisan schedule:work
npm run dev
```

Run those commands in separate terminals while Herd serves the site. A long-running queue worker must be restarted after changing `.env` or Reverb settings. Inspect failures with `php artisan queue:failed` and retry a corrected job with `php artisan queue:retry <id>`.

## Local setup with Docker Compose

The included Compose stack is for local development. It uses HTTP at `http://localhost:8088`, MySQL on host port `3307`, and Reverb on host port `8081`, so it can coexist with Herd’s usual ports.

1. Create the Docker environment file and build the app image:

    ```bash
    cp .env.docker.example .env.docker
    docker compose build
    ```

2. Generate an application key and copy the printed `base64:...` value into `APP_KEY` in `.env.docker`:

    ```bash
    docker compose run --rm --no-deps app php artisan key:generate --show
    ```

3. Start the services, install the schema and seed development data, then create an administrator:

    ```bash
    docker compose up -d
    docker compose exec app php artisan migrate --seed --force
    docker compose exec app php artisan tyro-dashboard:createsuperuser
    ```

4. Open `http://localhost:8088/`. Follow the service output with:

    ```bash
    docker compose logs -f app queue scheduler reverb
    ```

The app image builds frontend assets with the local Docker Reverb key. Rebuild it if you change that key or frontend build-time settings. `docker compose down` stops containers while preserving the database and uploaded files in named volumes. This Compose stack is a development convenience, not a production deployment recipe.

## Workspaces

| Workspace           | Typical tasks                                                                                                                                                 |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Admin: `/dashboard` | Manage tickets, people, categories, priorities, the business calendar and SLA policy; review analytics, imports, notifications, access, and timezone settings |
| Agent: `/dashboard` | Work assigned tickets, claim eligible unassigned tickets, update status, reply publicly, add internal notes, and review workload                              |
| Customer: `/portal` | Create and track requests, communicate with support, manage profile details, and receive ticket updates                                                       |

Permissions and ticket ownership are enforced on the server. The customer and agent views expose only the actions appropriate to their role; internal notes are staff-only.

## Business rules worth knowing

### Automatic assignment

Automatic assignment is not a blind turn counter. Eligible active agents must have an active agent role/profile and automatic assignment enabled. The allocator chooses the agent with the fewest live tickets; ties go to the agent least recently auto-assigned, then the lowest user ID. A shared lock protects concurrent assignment. If nobody is eligible, the ticket remains clearly unassigned. Manual assignment is never silently overwritten.

### SLA calculation

Ticket creation saves the selected policy, calendar, and target so later configuration changes do not rewrite historical tickets. SLA time is calculated in the calendar timezone (default `Asia/Dhaka`) and stored as UTC timestamps. The business calendar uses configured weekly hours and holidays; closed time is excluded. Waiting-for-customer and resolved intervals pause the SLA. Reopening resumes the remaining budget and preserves the first recorded breach. The scheduler detects approaching and breached stages without incrementing a timer every second.

See [business logic](docs/05-business-logic.md) for the exact formulae, state transitions, tie-breaks, and edge cases.

## User Management API

The API covers the admin user module only; it does not expose the ticket API or Tyro’s generic role/privilege endpoints.

- Base path: `/api/v1`
- Token endpoint: `POST /api/v1/auth/token`
- User endpoints: list, create, show, update, status, archive, and restore
- Access: active administrator with `users.manage`, plus a short-lived bearer token with the same ability

Start with the [API guide](docs/api/user-management/README.md). It links to authentication, endpoint details, the OpenAPI 3.1 specification, and the [Postman collection and environment](docs/api/user-management/postman/README.md).

## Realtime ticket updates

The customer portal subscribes to private `user.{id}` channels. Ticket status/assignment/priority changes and public replies arrive as `ticket.updated`; private notes and private attachment paths are not sent. Channel authorization is defined in `routes/channels.php`.

For Herd, Reverb must use the Herd certificate and matching origin. The local `.env.example` values are configured for `ticketms.test`; the corresponding command is included above. For Docker, Compose uses internal HTTP between services and publishes Reverb at `localhost:8081` for the browser.

See [Realtime integration](docs/realtime/ticket-message-events.md) for the payload contract, channel rules, setup, and troubleshooting.

## Queues and scheduled work

The default queue driver is the database. Workers process `imports`, `sla`, `notifications-database`, `notifications-mail`, and `default` queues. Ticket activity and durable delivery intents are recorded with the business transaction; notification dispatch and SLA evaluation run asynchronously. Import recovery runs every five minutes.

| Scheduled command                  | Frequency          | Purpose                                             |
| ---------------------------------- | ------------------ | --------------------------------------------------- |
| `ticketing:dispatch-notifications` | Every minute       | Queue due database and mail notification deliveries |
| `ticketing:scan-sla`               | Every minute       | Queue bounded SLA threshold evaluations             |
| `ticketing:recover-imports`        | Every five minutes | Resume stalled import checkpoints                   |

`composer run dev` and the Docker `scheduler` service run Laravel’s scheduler continuously. On a server that uses system cron, run `php artisan schedule:run` once per minute:

```cron
* * * * * cd /path/to/ticketms && php artisan schedule:run >> /dev/null 2>&1
```

Production must also supervise persistent queue workers and Reverb. Review [background processing](docs/09-background-processing.md) and [operations](docs/11-performance-and-operations.md) before deployment.

## Tests and CI

Run the main test suite and frontend checks locally:

```bash
php artisan test --compact
npm run build
npm run types:check
npm run check
```

MySQL-specific integration tests are opt-in and must use a disposable schema:

```bash
RUN_MYSQL_SCHEMA_TESTS=1 php artisan test --compact tests/Integration
```

GitHub Actions runs on pushes to `main` and pull requests. It installs PHP 8.4 and Node 22, audits Composer/npm dependencies, builds assets, then runs `composer ci:check`. Weekly Dependabot updates cover Composer, npm, and GitHub Actions. No automated deployment workflow is configured.

## Documentation map

| Document                                                                                                 | What it covers                                                          |
| -------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| [Documentation index](docs/01-README.md)                                                                 | Numbered project documentation and reading order                        |
| [Initial requirements](docs/02-initial-requirements.md) and [PRD](docs/03-PRD.md)                        | Source brief, product scope, decisions, and acceptance criteria         |
| [Architecture](docs/04-architecture.md)                                                                  | Laravel/Tyro boundaries, service patterns, and architecture decisions   |
| [Business logic](docs/05-business-logic.md)                                                              | Ticket state machine, assignment, SLA formulas, roles, and edge cases   |
| [Database design](docs/06-database-design.md)                                                            | Tables, relationships, constraints, indexes, and transactions           |
| [Security](docs/07-security-and-authorization.md)                                                        | Authentication, authorization, ownership, storage, and threat controls  |
| [API contracts](docs/08-api-and-integration-contracts.md)                                                | Browser, import, and integration contracts                              |
| [Background processing](docs/09-background-processing.md)                                                | Jobs, queue names, retry behavior, events, notifications, and schedules |
| [Testing strategy](docs/10-testing-strategy.md)                                                          | Test scenarios, evidence, and remaining acceptance checks               |
| [Performance and operations](docs/11-performance-and-operations.md)                                      | Query, observability, backup, and deployment guidance                   |
| [Implementation plan](docs/12-implementation-plan.md) and [traceability](docs/13-traceability-matrix.md) | Delivery history and requirements mapped to implementation/tests        |
| [Agent guidelines](docs/14-ai-agent-guidelines.md)                                                       | Repository-specific rules for coding agents                             |
| [User API docs](docs/api/user-management/)                                                               | API authentication, endpoints, OpenAPI, Postman collection/environment  |
| [Realtime integration guide](docs/realtime/ticket-message-events.md)                                     | Reverb setup, private channels, payload, and frontend integration       |

## Configuration and deployment

Keep credentials in local environment files or your deployment secret manager. Never commit `.env`, `.env.docker`, production keys, or customer data. Review queue supervision, TLS/reverse-proxy settings, mail transport, storage backups, database backups, and monitoring before production use. The Docker Compose stack and Herd values in this README are for local development.
