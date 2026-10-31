<?php

namespace Database\Seeders;

use App\Models\AgentProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TicketingTestAccountsSeeder extends Seeder
{
    /** @var array<string, array{name: string, email: string, password: string, role: string}> */
    private const ACCOUNTS = [
        'user' => [
            'name' => 'Test Customer',
            'email' => 'user@user.com',
            'password' => 'password',
            'role' => 'customer',
        ],
        'agent' => [
            'name' => 'Test Agent',
            'email' => 'agent@agent.com',
            'password' => 'password',
            'role' => 'agent',
        ],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $emails = array_map(fn (array $account): string => Str::lower(trim($account['email'])), self::ACCOUNTS);

        if (count(array_unique($emails)) !== count($emails)) {
            throw new RuntimeException('The test user and agent must use different email addresses.');
        }

        foreach (self::ACCOUNTS as $account) {
            $this->seedAccount($account);
        }
    }

    /** @param array{name?: string|null, email?: string|null, password?: string|null, role: string} $account */
    private function seedAccount(array $account): void
    {
        $name = trim((string) ($account['name'] ?? ''));
        $email = Str::lower(trim((string) ($account['email'] ?? '')));
        $password = (string) ($account['password'] ?? '');
        $role = Role::query()
            ->where('name', $account['role'])
            ->where('guard_name', 'web')
            ->active()
            ->firstOrFail();

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw new RuntimeException("The configured test {$account['role']} account is incomplete.");
        }

        DB::transaction(function () use ($account, $name, $email, $password, $role): void {
            $user = User::withTrashed()->where('email', $email)->lockForUpdate()->first();

            if ($user !== null && ($user->trashed() || ! $user->is_active || ! $user->hasRole($role))) {
                throw new RuntimeException("The test account {$email} already exists in a different account state.");
            }

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ]);
                $user->assignRole($role);
            }

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            if ($account['role'] === 'agent') {
                AgentProfile::query()->firstOrCreate(
                    ['user_id' => $user->getKey()],
                    ['auto_assignment_enabled' => true],
                );
            }
        });
    }
}
