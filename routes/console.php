<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('hr:send-mail')->everyMinute()->withoutOverlapping(10);
Schedule::command('hr:backup')->dailyAt('02:00')->timezone('Asia/Manila')->withoutOverlapping();
