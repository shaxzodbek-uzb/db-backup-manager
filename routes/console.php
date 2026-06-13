<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Evaluate every scheduled backup plan once a minute and dispatch the due ones.
Schedule::command('backups:dispatch-due')->everyMinute()->withoutOverlapping();
