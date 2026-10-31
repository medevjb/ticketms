<?php

namespace App\Services\Customer;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

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
        try {
            if (! $customer->save()) {
                throw new RuntimeException('The profile image could not be saved.');
            }
        } catch (Throwable $exception) {
            $this->deleteAvatarFile($newAvatarPath, (int) $customer->getKey());
            throw $exception;
        }

        if (is_string($previousAvatarPath)) {
            $this->deleteAvatarFile($previousAvatarPath, (int) $customer->getKey());
        }
    }

    public function removeAvatar(User $customer): void
    {
        $previousAvatarPath = $customer->avatar_path;
        $customer->avatar_path = null;
        if (! $customer->save()) {
            throw new RuntimeException('The profile image could not be removed.');
        }

        if (is_string($previousAvatarPath)) {
            $this->deleteAvatarFile($previousAvatarPath, (int) $customer->getKey());
        }
    }

    private function deleteAvatarFile(string $path, int $customerId): void
    {
        if (! str_starts_with($path, 'avatars/'.$customerId.'/')) {
            return;
        }

        try {
            if (! Storage::disk('local')->delete($path)) {
                Log::warning('Unable to remove a customer profile image from private storage.', ['user_id' => $customerId]);
            }
        } catch (Throwable $exception) {
            Log::warning('Unable to remove a customer profile image from private storage.', [
                'user_id' => $customerId,
                'exception' => $exception::class,
            ]);
        }
    }
}
