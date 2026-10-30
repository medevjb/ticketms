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

#[Signature('ticketing:make-admin')]
#[Description('Create the initial admin using the application Spatie role store.')]
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

        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->error('An account with that email already exists, including archived accounts.');

            return self::FAILURE;
        }

        $role = Role::query()->where('name', 'admin')->where('guard_name', 'web')->active()->first();
        if ($role === null) {
            $this->error('The admin role is missing. Run the database seeders first.');

            return self::FAILURE;
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
