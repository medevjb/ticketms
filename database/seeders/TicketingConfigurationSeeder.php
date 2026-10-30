<?php

namespace Database\Seeders;

use App\Models\CalendarVersion;
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
            $settings = TicketingSetting::query()->lockForUpdate()->findOrFail(1);

            foreach (['admin', 'agent', 'customer'] as $name) {
                $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);

                if ($role->wasRecentlyCreated) {
                    $role->forceFill(['is_system' => true])->save();
                }
            }

            Category::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);

            if ($settings->current_sla_policy_id !== null) {
                return;
            }

            $calendar = new CalendarVersion;
            $calendar->forceFill([
                'version_no' => 1,
                'name' => 'Default support calendar',
                'timezone' => 'Asia/Dhaka',
                'publication_state' => 'published',
                'published_at' => now(),
            ])->save();

            foreach ([7, 1, 2, 3, 4] as $weekday) {
                $calendar->windows()->forceCreate([
                    'weekday' => $weekday,
                    'opens_at' => '09:00:00',
                    'closes_at' => '18:00:00',
                ]);
            }

            $policy = new SlaPolicy;
            $policy->forceFill([
                'version_no' => 1,
                'name' => 'Default resolution SLA',
                'calendar_version_id' => $calendar->getKey(),
                'warning_percent' => 80,
                'publication_state' => 'published',
                'published_at' => now(),
            ])->save();

            foreach (['low' => 48, 'medium' => 24, 'high' => 8, 'critical' => 2] as $slug => $hours) {
                $priority = Priority::query()->firstOrCreate(['slug' => $slug], [
                    'name' => ucfirst($slug),
                    'sort_order' => $hours,
                ]);

                $policy->targets()->forceCreate([
                    'priority_id' => $priority->getKey(),
                    'target_seconds' => $hours * 3600,
                ]);
            }

            $settings->forceFill(['current_sla_policy_id' => $policy->getKey()])->save();
        }, attempts: 3);
    }
}
