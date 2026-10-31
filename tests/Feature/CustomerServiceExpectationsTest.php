<?php

use App\Models\BusinessCalendar;
use App\Models\CalendarHoliday;
use App\Models\Category;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CustomerSlaOverviewService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
});

it('shows business-time resolution estimates and open status during office hours', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00', 'Asia/Dhaka'));
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($customer)->get(route('customer.tickets.create'))->assertOk()
        ->assertSee('Support is open now')->assertSee('Estimated resolution if submitted now');
    $response = $this->getJson(route('customer.tickets.sla-preview'))->assertOk()->assertJsonPath('availability.is_open', true);
    $critical = collect($response->json('targets'))->firstWhere('priority', 'Critical');
    expect(CarbonImmutable::parse($critical['due_at'])->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-01 12:00');
});

it('moves after-hours estimates to the next business opening', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 19:00:00', 'Asia/Dhaka'));

    $summary = app(CustomerSlaOverviewService::class)->summary();

    expect($summary['availability']['state'])->toBe('outside_hours');
    expect(CarbonImmutable::parse($summary['availability']['next_open_at'])->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-02 09:00');
    $critical = collect($summary['targets'])->firstWhere('priority', 'Critical');
    expect(CarbonImmutable::parse($critical['due_at'])->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-02 11:00');
});

it('announces a configured holiday on creation and skips it when estimating resolution', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00', 'Asia/Dhaka'));
    $policy = SlaPolicy::query()->firstOrFail();
    CalendarHoliday::factory()->create(['business_calendar_id' => BusinessCalendar::query()->firstOrFail()->id, 'holiday_date' => '2026-11-01', 'name' => 'Support holiday']);
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($customer)->get(route('customer.tickets.create'))->assertOk()->assertSee('Support is closed for a holiday: Support holiday')->assertSee('Service resumes');
    $response = $this->getJson(route('customer.tickets.sla-preview'))->assertOk()->assertJsonPath('availability.state', 'holiday');
    $critical = collect($response->json('targets'))->firstWhere('priority', 'Critical');
    expect(CarbonImmutable::parse($critical['due_at'])->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-02 11:00');
});

it('explains weekend closure and shows when service resumes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:00', 'Asia/Dhaka'));

    $summary = app(CustomerSlaOverviewService::class)->summary();

    expect($summary['availability']['state'])->toBe('closed_day');
    expect(CarbonImmutable::parse($summary['availability']['next_open_at'])->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-01 09:00');
});

it('uses the same after-hours deadline in the creation preview and the saved customer ticket', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 19:00:00', 'Asia/Dhaka'));
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());
    $target = collect(app(CustomerSlaOverviewService::class)->summary()['targets'])->firstWhere('priority', 'Critical');

    $this->actingAs($customer)->post(route('customer.tickets.store'), [
        'operation_key' => (string) Str::uuid(),
        'category_id' => Category::query()->active()->firstOrFail()->id,
        'priority_id' => $target['priority_id'], 'subject' => 'After-hours estimate matches saved target',
        'description' => 'A request submitted while support is closed.',
    ])->assertRedirect();

    $ticket = Ticket::query()->where('subject', 'After-hours estimate matches saved target')->firstOrFail();
    expect($ticket->sla_due_at->equalTo(CarbonImmutable::parse($target['due_at'])))->toBeTrue();
    $page = $this->get(route('customer.tickets.show', $ticket));
    $page->assertOk()->assertSee('Expected resolution by')->assertSee('Service resumes');
    $this->get(route('customer.tickets.index'))->assertOk()->assertSee('Resolution target');
    $this->get(route('customer.dashboard'))->assertOk()->assertSee('Resolution target');
});
