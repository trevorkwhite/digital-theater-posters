<?php

use Illuminate\Support\Facades\Schedule;

/*
| Run by `php artisan schedule:run` every minute (Windows Scheduled Task, see
| windows/install-autostart.ps1). Order matters: the cheap auto-restore runs
| before a potentially long sync.
*/

Schedule::command('display:auto-restore')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('posters:sync')
    ->cron((string) config('posters.sync.schedule', '15 */6 * * *'))
    ->withoutOverlapping(120);
