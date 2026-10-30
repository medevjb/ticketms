<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
});

test('signed in customers and staff can view the public landing page', function (): void {
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $staff = User::factory()->create();
    $staff->assignRole(Role::query()->where('name', 'agent')->firstOrFail());

    $this->actingAs($customer)->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->where('isCustomerAccount', true)
            ->where('dashboardUrl', route('customer.dashboard')));

    $this->actingAs($staff)->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->where('isCustomerAccount', false)
            ->where('dashboardUrl', route('tyro-dashboard.index')));
});

test('customers can view their profile and update their display name', function (): void {
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($customer)->get(route('customer.profile'))->assertOk()->assertSee('Profile & settings', false);

    $this->actingAs($customer)->put(route('customer.profile.update'), ['name' => 'Updated Customer'])
        ->assertRedirect(route('customer.profile'));

    expect($customer->fresh()->name)->toBe('Updated Customer');
});

test('only customers can use customer profile routes', function (): void {
    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());

    $this->actingAs($agent)->get(route('customer.profile'))->assertNotFound();
});

test('customers must provide their current password before changing it', function (): void {
    $customer = User::factory()->create(['password' => 'CurrentPassword123!']);
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($customer)->put(route('customer.profile.password'), [
        'current_password' => 'incorrect-password',
        'password' => 'NewPassword123!Secure',
        'password_confirmation' => 'NewPassword123!Secure',
    ])->assertSessionHasErrors('current_password');

    expect(Hash::check('CurrentPassword123!', $customer->fresh()->password))->toBeTrue();

    $this->actingAs($customer)->put(route('customer.profile.password'), [
        'current_password' => 'CurrentPassword123!',
        'password' => 'NewPassword123!Secure',
        'password_confirmation' => 'NewPassword123!Secure',
    ])->assertRedirect(route('customer.profile'));

    expect(Hash::check('NewPassword123!Secure', $customer->fresh()->password))->toBeTrue();
});

test('customers can upload and remove a private profile photo', function (): void {
    Storage::fake('local');
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($customer)->post(route('customer.profile.avatar.update'), [
        'avatar' => UploadedFile::fake()->image('profile.png', 128, 128),
    ])->assertRedirect(route('customer.profile'));

    $avatarPath = $customer->fresh()->avatar_path;
    expect($avatarPath)->toStartWith('avatars/'.$customer->id.'/');
    Storage::disk('local')->assertExists($avatarPath);

    $this->actingAs($customer)->get(route('customer.profile.avatar'))->assertOk();

    $this->actingAs($customer)->delete(route('customer.profile.avatar.destroy'))
        ->assertRedirect(route('customer.profile'));

    expect($customer->fresh()->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($avatarPath);
});
