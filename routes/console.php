<?php

use App\Domain\Mail\Models\EmailLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Licence signing keys (module 1.4): delete retired keys past the keep period.
Schedule::command('licence:keys:prune')->daily()->onOneServer();

// Email log (module 1.7): delete rows older than the retention period (12 months).
Schedule::command('model:prune', ['--model' => [EmailLog::class]])->daily()->onOneServer();
