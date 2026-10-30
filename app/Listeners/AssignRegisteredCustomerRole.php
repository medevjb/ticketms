<?php

namespace App\Listeners;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

class AssignRegisteredCustomerRole
{
    public function handle(Registered $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        DB::transaction(function () use ($event): void {
            $role = Role::query()
                ->where('name', 'customer')
                ->where('guard_name', 'web')
                ->where('is_active', true)
                ->firstOrFail();

            $event->user->assignRole($role);
        }, attempts: 3);
    }
}
