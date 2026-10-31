<?php

use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
});

it('saves the staff timezone through admin settings without changing other environment values', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $this->actingAs($admin)->get(route('tyro-dashboard.settings.system.index'))->assertOk()->assertSee('Save timezone');
    $directory = sys_get_temp_dir().'/ticketms-timezone-'.Str::uuid();
    File::makeDirectory($directory);
    File::put($directory.'/.env', "APP_NAME=Test\nTICKETING_DISPLAY_TIMEZONE=Asia/Dhaka\n");
    $this->app->useEnvironmentPath($directory)->loadEnvironmentFrom('.env');
    Artisan::shouldReceive('call')->once()->with('config:clear')->andReturn(0);

    try {
        $this->actingAs($admin)->put(route('dashboard.settings.timezone'), ['timezone' => 'America/New_York'])
            ->assertRedirectToRoute('tyro-dashboard.settings.system.index')->assertSessionHas('success');
        expect(File::get($directory.'/.env'))->toBe("APP_NAME=Test\nTICKETING_DISPLAY_TIMEZONE=\"America/New_York\"\n");
        expect(config('app.timezone'))->toBe('UTC');
        expect(config('ticketing.display_timezone'))->toBe('America/New_York');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('applies the staff timezone to ticket timestamps and date filter boundaries', function (): void {
    config(['ticketing.display_timezone' => 'America/New_York']);
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $included = Ticket::factory()->create(['created_at' => '2026-11-03 02:00:00']);
    Ticket::factory()->create(['created_at' => '2026-11-03 06:00:00']);

    $response = $this->actingAs($admin)->getJson(route('dashboard.tickets.data', [
        'draw' => 1, 'start' => 0, 'length' => 15, 'date_from' => '2026-11-02', 'date_to' => '2026-11-02',
    ]));

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.ticket'))->toContain($included->number);
    expect($response->json('data.0.created'))->toContain('02 Nov 2026')->toContain('09:00 PM');
});

it('rejects invalid timezones and restricts timezone settings to administrators', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $this->actingAs($admin)->put(route('dashboard.settings.timezone'), ['timezone' => 'Invalid/Timezone'])->assertSessionHasErrors('timezone');

    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    $this->actingAs($agent)->put(route('dashboard.settings.timezone'), ['timezone' => 'UTC'])->assertRedirect();
});
