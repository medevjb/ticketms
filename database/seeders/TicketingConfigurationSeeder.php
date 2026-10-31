<?php

namespace Database\Seeders;

use App\Models\BusinessCalendar;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\TicketingSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TicketingConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            TicketingSetting::query()->lockForUpdate()->findOrFail(1);

            foreach (['admin', 'agent', 'customer'] as $name) {
                $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);

                if ($role->wasRecentlyCreated) {
                    $role->forceFill(['is_system' => true])->save();
                }
            }

            Category::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);

            $calendar = BusinessCalendar::query()->first();
            if ($calendar === null) {
                $calendar = new BusinessCalendar;
                $calendar->forceFill([
                    'name' => 'Default support calendar',
                    'timezone' => 'Asia/Dhaka',
                ])->save();
            }

            if ($calendar->windows()->doesntExist()) {
                foreach ([7, 1, 2, 3, 4] as $weekday) {
                    $calendar->windows()->forceCreate([
                        'weekday' => $weekday,
                        'opens_at' => '09:00:00',
                        'closes_at' => '18:00:00',
                    ]);
                }
            }

            $policy = SlaPolicy::query()->first();
            if ($policy === null) {
                $policy = new SlaPolicy;
                $policy->forceFill([
                    'name' => 'Default resolution SLA',
                    'warning_percent' => 80,
                ])->save();
            }

            foreach (['low' => 48, 'medium' => 24, 'high' => 8, 'critical' => 2] as $slug => $hours) {
                $priority = Priority::query()->firstOrCreate(['slug' => $slug], [
                    'name' => ucfirst($slug),
                    'sort_order' => $hours,
                ]);

                $policy->targets()->firstOrCreate(
                    ['priority_id' => $priority->getKey()],
                    ['target_seconds' => $hours * 3600],
                );
            }
        }, attempts: 3);
    }
}
