<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Evaluate every scheduled backup plan once a minute and dispatch the due ones.
//
// The expiry is the whole point of the number. `withoutOverlapping()` with no
// argument holds its mutex for a day, so a run that dies without releasing it
// takes the next twenty-four hours of backups with it — every minute of them
// skipped, silently, because a skipped command logs nothing. That happened on
// 2026-09-16: the mutex was stuck, `schedule:list` showed "Has Mutex" against
// this line, and the whole night's window for all six plans went by with the
// dispatcher blocked. Ten minutes is far longer than dispatching can take and
// short enough that a crash costs one window at worst.
Schedule::command('backups:dispatch-due')->everyMinute()->withoutOverlapping(10);

// A run that fails leaves a row saying so. A run that never happens leaves
// nothing at all, which is how these outages have gone unnoticed until somebody
// thought to look. Four times a day catches a missed night by morning and
// leaves a healthy week silent.
//
// The expiry matters more here than above: this is the command that reports the
// outage. A watchdog that its own stuck lock can silence for a day is not a
// watchdog. Thirty minutes is well inside its own six-hour cadence.
Schedule::command('backups:check-health')->everySixHours()->withoutOverlapping(30);
