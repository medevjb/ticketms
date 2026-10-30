<?php

use App\Enums\TicketStatus;
use App\Enums\TicketTransition;
use App\Models\ActivityEntry;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\CommandReceipt;
use App\Models\Priority;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\SlaPolicyTarget;
use App\Models\Ticket;
use App\Models\TicketingSetting;
use App\Models\TicketSlaInterval;
use App\Models\User;
use App\Services\Analytics\TicketAnalyticsService;
use App\Services\Tickets\AssignmentService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('mysql commits ticket creation, sequence, status history, and SLA pause atomically', function () {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);

    $admin = User::factory()->create();
    $customer = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());
    $category = Category::query()->active()->firstOrFail();
    $priority = Priority::query()->where('slug', 'critical')->firstOrFail();
    $operationKey = (string) Str::uuid();

    $this->actingAs($admin)->post(route('dashboard.tickets.store'), [
        'operation_key' => $operationKey,
        'customer_id' => $customer->getKey(),
        'category_id' => $category->getKey(),
        'priority_id' => $priority->getKey(),
        'subject' => 'MySQL ticket transaction',
        'description' => 'Verify the write transaction on the MySQL integration schema.',
    ])->assertRedirect();

    $ticket = Ticket::query()->firstOrFail();
    expect($ticket->number)->toMatch('/^TKT-'.now('Asia/Dhaka')->year.'-000001$/')
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and(ActivityEntry::query()->where('ticket_id', $ticket->getKey())->where('action', 'ticket.created')->exists())->toBeTrue();

    $this->patch(route('dashboard.tickets.transition', $ticket->getKey()), [
        'transition' => TicketTransition::Wait->value,
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'public_message' => 'Please provide the requested details.',
    ])->assertRedirect();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::WaitingForCustomer)
        ->and($ticket->sla_due_at)->toBeNull()
        ->and(TicketSlaInterval::query()->where('ticket_id', $ticket->getKey())->where('kind', 'waiting')->whereNull('ended_at')->exists())->toBeTrue()
        ->and(ActivityEntry::query()->where('ticket_id', $ticket->getKey())->where('action', 'ticket.wait')->exists())->toBeTrue();

    $this->actingAs($customer)->post(route('customer.tickets.messages.store', $ticket), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'visibility' => 'public',
        'body' => 'The issue is still happening after the requested check.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->sla_paused_at)->toBeNull()
        ->and($ticket->sla_due_at)->not->toBeNull()
        ->and($ticket->sla_warning_at)->not->toBeNull()
        ->and(ActivityEntry::query()->where('ticket_id', $ticket->getKey())->where('action', 'ticket.resumed_by_customer_reply')->exists())->toBeTrue();
});

test('mysql assigns created tickets to eligible agents without changing ticket lifecycle or SLA state', function () {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);

    $admin = User::factory()->create();
    $customer = User::factory()->create();
    $agent = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    AgentProfile::factory()->create([
        'user_id' => $agent->getKey(),
        'auto_assignment_enabled' => true,
    ]);

    $this->actingAs($admin)->post(route('dashboard.tickets.store'), [
        'operation_key' => (string) Str::uuid(),
        'customer_id' => $customer->getKey(),
        'category_id' => Category::query()->active()->firstOrFail()->getKey(),
        'priority_id' => Priority::query()->where('slug', 'high')->firstOrFail()->getKey(),
        'subject' => 'Assigned in MySQL transaction',
        'description' => 'Verify assignment persistence and SLA invariants.',
    ])->assertRedirect();

    $ticket = Ticket::query()->where('subject', 'Assigned in MySQL transaction')->firstOrFail();
    expect($ticket->assigned_agent_id)->toBe($agent->getKey())
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->lock_version)->toBe(2)
        ->and($ticket->sla_started_at)->not->toBeNull()
        ->and($ticket->sla_due_at)->not->toBeNull()
        ->and(ActivityEntry::query()->where('ticket_id', $ticket->getKey())->where('action', 'ticket.auto_assigned')->exists())->toBeTrue();
});

test('mysql analytics uses Dhaka midnight boundaries for created ticket buckets', function () {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $admin = User::factory()->create();
    $customer = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'created_by' => $customer->getKey(),
        'created_at' => CarbonImmutable::parse('2026-11-01 17:59:59', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-11-01 17:59:59', 'UTC'),
    ]);
    Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'created_by' => $customer->getKey(),
        'created_at' => CarbonImmutable::parse('2026-11-01 18:00:00', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-11-01 18:00:00', 'UTC'),
    ]);

    $analytics = app(TicketAnalyticsService::class)->dashboard($admin, '2026-11-02', '2026-11-02');

    expect($analytics['metrics']['created'])->toBe(1)
        ->and($analytics['daily'])->toHaveCount(1)
        ->and($analytics['daily'][0])->toMatchArray(['date' => '2026-11-02', 'created' => 1]);
});

test('mysql assignment coordinator prevents a competing connection from entering the assignment lock window', function () {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    DB::commit();
    DB::beginTransaction();

    app(AssignmentService::class)->lockCoordinator();
    config(['database.connections.mysql_assignment_contender' => config('database.connections.mysql_schema_test')]);
    $contender = DB::connection('mysql_assignment_contender');
    $contender->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $contender->beginTransaction();

    try {
        expect(fn () => $contender->table('ticketing_settings')->where('id', 1)->lockForUpdate()->first())
            ->toThrow(QueryException::class);
    } finally {
        $contender->rollBack();
        DB::rollBack();
    }
});

test('mysql simultaneous claims and manual assignment produce one winner and one delivery-intent set', function () {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);

    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $customer = User::factory()->create();
    $agents = User::factory()->count(2)->create();
    $agentRole = Role::query()->where('name', 'agent')->firstOrFail();
    foreach ($agents as $agent) {
        $agent->assignRole($agentRole);
        AgentProfile::factory()->create(['user_id' => $agent->getKey(), 'auto_assignment_enabled' => false]);
    }
    $settings = TicketingSetting::query()->findOrFail(1);
    $policy = SlaPolicy::query()->whereKey($settings->current_sla_policy_id)->firstOrFail();
    $priority = Priority::query()->where('slug', 'high')->firstOrFail();
    $target = SlaPolicyTarget::query()
        ->where('sla_policy_id', $policy->getKey())
        ->where('priority_id', $priority->getKey())
        ->firstOrFail();
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'created_by' => $customer->getKey(),
        'category_id' => Category::query()->active()->firstOrFail()->getKey(),
        'priority_id' => $priority->getKey(),
        'sla_policy_id' => $policy->getKey(),
        'calendar_version_id' => $policy->calendar_version_id,
        'sla_target_seconds' => $target->target_seconds,
    ])->refresh();
    $agentIds = $agents->modelKeys();
    $operationKeys = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
    $ticketId = (int) $ticket->getKey();
    $version = (int) $ticket->lock_version;
    $connection = config('database.connections.mysql_schema_test');
    $connection['database'] = DB::connection()->getDatabaseName();
    $directory = sys_get_temp_dir().'/ticketms-claim-'.(string) Str::uuid();
    mkdir($directory, 0700, true);
    $barrier = $directory.'/start';
    touch($barrier);
    $workers = [];
    $userIds = [$admin->getKey(), $customer->getKey(), ...$agentIds];
    $workerSpecs = [
        ['actor_id' => $agentIds[0], 'action' => 'claim', 'target_agent_id' => 0],
        ['actor_id' => $agentIds[1], 'action' => 'claim', 'target_agent_id' => 0],
        ['actor_id' => $admin->getKey(), 'action' => 'assign', 'target_agent_id' => $agentIds[1]],
    ];

    DB::commit();

    $workerScript = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = json_decode((string) getenv('TICKETMS_MYSQL_CONNECTION'), true, 512, JSON_THROW_ON_ERROR);
config(['database.connections.mysql_worker' => $connection, 'database.default' => 'mysql_worker']);
\Illuminate\Support\Facades\DB::purge('mysql_worker');
app('events')->forget(\App\Events\TicketActivityRecorded::class);
file_put_contents((string) getenv('TICKETMS_WORKER_READY'), 'ready');
$barrier = (string) getenv('TICKETMS_WORKER_BARRIER');
$deadline = microtime(true) + 10;
while (file_exists($barrier) && microtime(true) < $deadline) { usleep(1000); }
if (file_exists($barrier)) { fwrite(STDERR, 'Claim barrier timed out.'); exit(2); }
$actor = \App\Models\User::query()->findOrFail((int) getenv('TICKETMS_ACTOR_ID'));
try {
    $assignment = app(\App\Services\Tickets\AssignmentService::class);
    if (getenv('TICKETMS_ACTION') === 'assign') {
        $assignment->assign(
            $actor,
            (int) getenv('TICKETMS_TICKET_ID'),
            (int) getenv('TICKETMS_TARGET_AGENT_ID'),
            (int) getenv('TICKETMS_TICKET_VERSION'),
            (string) getenv('TICKETMS_OPERATION_KEY'),
        );
    } else {
        $assignment->claim(
            $actor,
            (int) getenv('TICKETMS_TICKET_ID'),
            (int) getenv('TICKETMS_TICKET_VERSION'),
            (string) getenv('TICKETMS_OPERATION_KEY'),
        );
    }
    fwrite(STDOUT, 'success');
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    fwrite(STDOUT, 'http:'.$exception->getStatusCode().':'.$exception->getMessage());
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(2);
}
PHP;

    try {
        foreach ($workerSpecs as $index => $specification) {
            $environment = array_merge(getenv() ?: [], [
                'TICKETMS_MYSQL_CONNECTION' => json_encode($connection, JSON_THROW_ON_ERROR),
                'TICKETMS_WORKER_READY' => $directory.'/ready-'.$index,
                'TICKETMS_WORKER_BARRIER' => $barrier,
                'TICKETMS_ACTOR_ID' => (string) $specification['actor_id'],
                'TICKETMS_ACTION' => $specification['action'],
                'TICKETMS_TARGET_AGENT_ID' => (string) $specification['target_agent_id'],
                'TICKETMS_TICKET_ID' => (string) $ticketId,
                'TICKETMS_TICKET_VERSION' => (string) $version,
                'TICKETMS_OPERATION_KEY' => $operationKeys[$index],
            ]);
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-r', $workerScript], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, getcwd(), $environment);
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start a MySQL claim worker process.');
            }
            fclose($pipes[0]);
            $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        }

        $readyDeadline = microtime(true) + 10;
        while (microtime(true) < $readyDeadline) {
            if (count(glob($directory.'/ready-*') ?: []) === count($workerSpecs)) {
                break;
            }
            usleep(10000);
        }
        expect(count(glob($directory.'/ready-*') ?: []))->toBe(count($workerSpecs));
        unlink($barrier);

        $results = [];
        foreach ($workers as $worker) {
            $results[] = stream_get_contents($worker['stdout']);
            $errors = stream_get_contents($worker['stderr']);
            fclose($worker['stdout']);
            fclose($worker['stderr']);
            expect(proc_close($worker['process']))->toBe(0, $errors);
        }

        sort($results);
        expect(count(array_filter($results, fn (string $result): bool => str_starts_with($result, 'http:409:'))))->toBe(2)
            ->and(count(array_filter($results, fn (string $result): bool => str_starts_with($result, 'success'))))->toBe(1)
            ->and(in_array($ticket->fresh()->assigned_agent_id, $agentIds, true))->toBeTrue()
            ->and(ActivityEntry::query()->where('ticket_id', $ticketId)->whereIn('action', ['ticket.claimed', 'ticket.assigned'])->count())->toBe(1)
            ->and(DB::table('notification_deliveries')->whereIn('activity_entry_id', function ($query) use ($ticketId): void {
                $query->select('id')->from('activity_entries')->where('ticket_id', $ticketId)->whereIn('action', ['ticket.claimed', 'ticket.assigned']);
            })->count())->toBe(6)
            ->and(CommandReceipt::query()->whereIn('operation_key', $operationKeys)->count())->toBe(1);
    } finally {
        if (file_exists($barrier)) {
            unlink($barrier);
        }
        foreach ($workers as $worker) {
            if (is_resource($worker['stdout'])) {
                fclose($worker['stdout']);
            }
            if (is_resource($worker['stderr'])) {
                fclose($worker['stderr']);
            }
            if (is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }

        if (DB::connection()->transactionLevel() === 0) {
            DB::beginTransaction();
            $activityIds = DB::table('activity_entries')->where('ticket_id', $ticketId)->pluck('id');
            DB::table('notification_deliveries')->whereIn('activity_entry_id', $activityIds)->delete();
            DB::table('activity_entries')->where('ticket_id', $ticketId)->delete();
            DB::table('command_receipts')->whereIn('operation_key', $operationKeys)->delete();
            DB::table('tickets')->where('id', $ticketId)->delete();
            DB::table('agent_profiles')->whereIn('user_id', $agentIds)->delete();
            DB::table('access_model_has_roles')->whereIn('model_id', $userIds)->where('model_type', User::class)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
            DB::commit();
        }

        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});

test('mysql simultaneous administrator deactivation attempts leave one active administrator', function () {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);

    $administrators = User::factory()->count(2)->create();
    $adminRole = Role::query()->where('name', 'admin')->firstOrFail();
    foreach ($administrators as $administrator) {
        $administrator->assignRole($adminRole);
    }
    $administratorIds = $administrators->modelKeys();
    $connection = config('database.connections.mysql_schema_test');
    $connection['database'] = DB::connection()->getDatabaseName();
    $directory = sys_get_temp_dir().'/ticketms-admin-race-'.(string) Str::uuid();
    mkdir($directory, 0700, true);
    $barrier = $directory.'/start';
    touch($barrier);
    $workers = [];

    DB::commit();

    $workerScript = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = json_decode((string) getenv('TICKETMS_MYSQL_CONNECTION'), true, 512, JSON_THROW_ON_ERROR);
config(['database.connections.mysql_worker' => $connection, 'database.default' => 'mysql_worker']);
\Illuminate\Support\Facades\DB::purge('mysql_worker');
file_put_contents((string) getenv('TICKETMS_WORKER_READY'), 'ready');
$barrier = (string) getenv('TICKETMS_WORKER_BARRIER');
$deadline = microtime(true) + 10;
while (file_exists($barrier) && microtime(true) < $deadline) { usleep(1000); }
if (file_exists($barrier)) { fwrite(STDERR, 'Admin lifecycle barrier timed out.'); exit(2); }
$actor = \App\Models\User::query()->findOrFail((int) getenv('TICKETMS_ADMIN_ACTOR_ID'));
$target = \App\Models\User::query()->findOrFail((int) getenv('TICKETMS_ADMIN_TARGET_ID'));
try {
    app(\App\Services\Authorization\UserLifecycleService::class)->setActive($target, false, $actor);
    fwrite(STDOUT, 'success');
} catch (\Illuminate\Auth\Access\AuthorizationException) {
    fwrite(STDOUT, 'denied');
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(2);
}
PHP;

    try {
        foreach ($administratorIds as $index => $actorId) {
            $environment = array_merge(getenv() ?: [], [
                'TICKETMS_MYSQL_CONNECTION' => json_encode($connection, JSON_THROW_ON_ERROR),
                'TICKETMS_WORKER_READY' => $directory.'/ready-'.$index,
                'TICKETMS_WORKER_BARRIER' => $barrier,
                'TICKETMS_ADMIN_ACTOR_ID' => (string) $actorId,
                'TICKETMS_ADMIN_TARGET_ID' => (string) $administratorIds[1 - $index],
            ]);
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-r', $workerScript], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, getcwd(), $environment);
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start an administrator lifecycle worker process.');
            }
            fclose($pipes[0]);
            $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        }

        $readyDeadline = microtime(true) + 10;
        while (microtime(true) < $readyDeadline) {
            if (count(glob($directory.'/ready-*') ?: []) === count($administratorIds)) {
                break;
            }
            usleep(10000);
        }
        expect(count(glob($directory.'/ready-*') ?: []))->toBe(count($administratorIds));
        unlink($barrier);

        $results = [];
        foreach ($workers as $worker) {
            $results[] = stream_get_contents($worker['stdout']);
            $errors = stream_get_contents($worker['stderr']);
            fclose($worker['stdout']);
            fclose($worker['stderr']);
            expect(proc_close($worker['process']))->toBe(0, $errors);
        }

        sort($results);
        $remainingAdministrators = User::query()->active()->whereHas('roles', fn ($query) => $query
            ->where('access_roles.name', 'admin')
            ->where('access_roles.guard_name', 'web')
            ->where('access_roles.is_active', true))
            ->whereIn('users.id', $administratorIds)
            ->count();

        expect($results)->toBe(['denied', 'success'])
            ->and($remainingAdministrators)->toBe(1);
    } finally {
        if (file_exists($barrier)) {
            unlink($barrier);
        }
        foreach ($workers as $worker) {
            if (is_resource($worker['stdout'])) {
                fclose($worker['stdout']);
            }
            if (is_resource($worker['stderr'])) {
                fclose($worker['stderr']);
            }
            if (is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }

        if (DB::connection()->transactionLevel() === 0) {
            DB::beginTransaction();
            DB::table('access_model_has_roles')->whereIn('model_id', $administratorIds)->where('model_type', User::class)->delete();
            DB::table('users')->whereIn('updated_by', $administratorIds)->update(['updated_by' => null]);
            DB::table('users')->whereIn('deleted_by', $administratorIds)->update(['deleted_by' => null]);
            DB::table('users')->whereIn('id', $administratorIds)->delete();
            DB::commit();
        }

        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});
