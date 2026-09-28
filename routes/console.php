<?php

use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Ai\Models\AiUsage;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Shared\Support\SchedulerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Licence signing keys (module 1.4): delete retired keys past the keep period.
Schedule::command('licence:keys:prune')->daily()->onOneServer();

// Licences (module 1.3): move stored statuses along their dates (trial → grace → expired). Idempotent.
Schedule::command('licences:refresh')->dailyAt('00:05')->onOneServer()->withoutOverlapping();

// Cash billing (module 1.8): due invoices, overdue → suspension after the grace, trial emails. Idempotent.
Schedule::command('billing:run')->dailyAt('06:00')->onOneServer()->withoutOverlapping();

// Email log (module 1.7): delete rows older than the retention period (12 months).
Schedule::command('model:prune', ['--model' => [EmailLog::class]])->daily()->onOneServer();

// AI (module 5.1): conversations and proposals after `ai.retention_days`, usage rows after `ai.usage_retention_months`.
Schedule::command('model:prune', ['--model' => [AiConversation::class, AiPendingAction::class, AiUsage::class]])->dailyAt('02:30')->onOneServer();

// Admin dashboard (module 1.9): a heartbeat so System health can show the scheduler is running.
Schedule::call(fn () => SchedulerHeartbeat::beat())->everyMinute()->name('scheduler:heartbeat')->onOneServer();
