<?php

namespace Database\Seeders;

use App\Models\AgentProfile;
use App\Models\Role;
use App\Models\User;
use HasinHayder\Tyro\Support\PasswordRules;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class TicketingUsersSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['agent', 'customer'] as $roleName) {
            /** @var array{name?: string|null, email?: string|null, password?: string|null} $account */
            $account = config("ticketing.bootstrap_users.{$roleName}", []);

            $name = trim((string) ($account['name'] ?? ''));
            $email = mb_strtolower(trim((string) ($account['email'] ?? '')));
            $password = (string) ($account['password'] ?? '');

            if ($name === '' && $email === '' && $password === '') {
                continue;
            }

            $validator = Validator::make([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ], [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', ...PasswordRules::get(['name' => $name, 'email' => $email])],
            ]);

            if ($validator->fails()) {
                throw new RuntimeException("Invalid configured {$roleName} bootstrap account: ".$validator->errors()->first());
            }

            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->active()
                ->firstOrFail();

            $existingUser = User::withTrashed()->where('email', $email)->first();

            if ($existingUser !== null) {
                if (! $existingUser->trashed() && $existingUser->hasRole($role)) {
                    continue;
                }

                throw new RuntimeException("The configured {$roleName} email is already assigned to another account state.");
            }

            DB::transaction(function () use ($name, $email, $password, $role, $roleName): void {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ]);
                $user->assignRole($role);

                if ($roleName === 'agent') {
                    AgentProfile::query()->forceCreate([
                        'user_id' => $user->getKey(),
                        'auto_assignment_enabled' => true,
                    ]);
                }
            });
        }
    }
}
