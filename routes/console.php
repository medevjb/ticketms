<?php

use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

DevCommands::artisan(
    'queue:listen --tries=1 --timeout=0 --queue=imports,sla,notifications-database,notifications-mail,default',
    'queue',
);
DevCommands::artisan('schedule:work', 'scheduler');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ticketing:dispatch-notifications')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer();

Schedule::command('ticketing:scan-sla')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer();

Schedule::command('ticketing:recover-imports')
    ->everyFiveMinutes()
    ->withoutOverlapping(4)
    ->onOneServer();
