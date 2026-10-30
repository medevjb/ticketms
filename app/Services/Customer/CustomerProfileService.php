<?php

namespace App\Services\Customer;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CustomerProfileService
{
    public function updateName(User $customer, string $name): void
    {
        $customer->name = $name;
        $customer->save();
    }

    public function updatePassword(User $customer, string $password): void
    {
        $customer->password = $password;
        $customer->save();
    }

    public function updateAvatar(User $customer, UploadedFile $avatar): void
    {
        $newAvatarPath = $avatar->store('avatars/'.$customer->getKey(), 'local');

        if (! is_string($newAvatarPath)) {
            throw new RuntimeException('The profile image could not be stored.');
        }

        $previousAvatarPath = $customer->avatar_path;
        $customer->avatar_path = $newAvatarPath;
        $customer->save();

        if (is_string($previousAvatarPath)) {
            Storage::disk('local')->delete($previousAvatarPath);
        }
    }

    public function removeAvatar(User $customer): void
    {
        $previousAvatarPath = $customer->avatar_path;
        $customer->avatar_path = null;
        $customer->save();

        if (is_string($previousAvatarPath)) {
            Storage::disk('local')->delete($previousAvatarPath);
        }
    }
}
