<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Evaluate every scheduled backup plan once a minute and dispatch the due ones.
Schedule::command('backups:dispatch-due')->everyMinute()->withoutOverlapping();

// A run that fails leaves a row saying so. A run that never happens leaves
// nothing at all, which is how these outages have gone unnoticed until somebody
// thought to look. Four times a day catches a missed night by morning and
// leaves a healthy week silent.
Schedule::command('backups:check-health')->everySixHours()->withoutOverlapping();
