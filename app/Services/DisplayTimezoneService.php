<?php

namespace App\Services;

use App\Models\User;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DisplayTimezoneService
{
    public function save(User $actor, string $timezone): void
    {
        if (! in_array('admin', $actor->tyroRoleSlugs(), true) || ! $actor->can('users.manage')) {
            throw new AuthorizationException;
        }
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::withMessages(['timezone' => 'Choose a valid timezone.']);
        }

        $handle = @fopen(app()->environmentFilePath(), 'r+');
        if ($handle === false) {
            throw new RuntimeException('Timezone settings could not be saved.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Timezone settings could not be locked.');
            }
            $content = stream_get_contents($handle);
            if ($content === false) {
                throw new RuntimeException('Timezone settings could not be read.');
            }
            $line = 'TICKETING_DISPLAY_TIMEZONE="'.$timezone.'"';
            $updated = preg_match('/^TICKETING_DISPLAY_TIMEZONE=/m', $content)
                ? preg_replace('/^TICKETING_DISPLAY_TIMEZONE=.*$/m', $line, $content)
                : rtrim($content)."\n".$line."\n";
            rewind($handle);
            if (fwrite($handle, $updated) !== strlen($updated) || ! ftruncate($handle, strlen($updated))) {
                throw new RuntimeException('Timezone settings could not be written.');
            }
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        Artisan::call('config:clear');
        config(['ticketing.display_timezone' => $timezone]);
    }
}
