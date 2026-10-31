<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use HasinHayder\Tyro\Support\PasswordRules;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('tyro-dashboard:createsuperuser', aliases: ['ticketing:make-admin'])]
#[Description('Create an administrator using the application role store.')]
class CreateTicketingAdmin extends Command
{
    public function handle(): int
    {
        $name = trim((string) $this->ask('Name'));
        $email = Str::lower(trim((string) $this->ask('Email address')));
        $validator = Validator::make(['name' => $name, 'email' => $email], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::query()->where('name', 'admin')->where('guard_name', 'web')->active()->first();
        if ($role === null) {
            $this->error('The application admin role is missing. Run the application seeders first: php artisan db:seed.');

            return self::FAILURE;
        }

        $existingUser = User::withTrashed()->where('email', $email)->first();
        if ($existingUser !== null) {
            if ($existingUser->trashed() || ! $existingUser->is_active) {
                $this->error('This account is archived or disabled. Restore or enable it before granting admin access.');

                return self::FAILURE;
            }

            if ($existingUser->hasRole($role)) {
                $this->info("{$existingUser->email} already has admin access.");

                return self::SUCCESS;
            }

            if (! $this->confirm('This account already exists. Assign the admin role to it?', false)) {
                $this->line('No changes were made.');

                return self::SUCCESS;
            }

            DB::transaction(function () use ($existingUser, $role): void {
                $user = User::query()->whereKey($existingUser->getKey())->lockForUpdate()->firstOrFail();
                $user->forceFill([
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'authorization_version' => $user->authorization_version + 1,
                    'remember_token' => null,
                ])->save();
                $user->assignRole($role);
            });

            $this->info("Admin access assigned to {$existingUser->email}.");

            return self::SUCCESS;
        }

        while (true) {
            $password = (string) $this->secret('Password');
            $confirmation = (string) $this->secret('Password (again)');

            if (! hash_equals($password, $confirmation)) {
                $this->error('Passwords do not match.');

                continue;
            }

            $passwordValidator = Validator::make(['password' => $password], [
                'password' => PasswordRules::get(['name' => $name, 'email' => $email]),
            ]);

            if ($passwordValidator->fails()) {
                foreach ($passwordValidator->errors()->all() as $error) {
                    $this->error($error);
                }

                continue;
            }

            break;
        }

        $user = DB::transaction(function () use ($name, $email, $password, $role): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole($role);

            return $user;
        });

        $this->info("Admin account created for {$user->email}.");

        return self::SUCCESS;
    }
}
