<?php

use Illuminate\Support\Facades\Schedule;

// Shared hosting (KeyHelp) has no process supervisor: the scheduler cron
// works off queued mails every minute.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
