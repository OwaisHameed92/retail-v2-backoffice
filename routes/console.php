<?php

use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Ai\Models\AiUsage;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Notifications\Models\AlertNotification;
use App\Domain\Purchasing\Actions\PurgeInvoiceImportFiles;
use App\Domain\Purchasing\Models\InvoiceImport;
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

// GoCardless (module 1.12): heal missed webhooks and amount drift before the billing run.
Schedule::command('billing:reconcile-gocardless')->dailyAt('05:30')->onOneServer()->withoutOverlapping();

// Email log (module 1.7): delete rows older than the retention period (12 months).
Schedule::command('model:prune', ['--model' => [EmailLog::class]])->daily()->onOneServer();

// AI (module 6.1): conversations and proposals after `ai.retention_days`, usage rows after `ai.usage_retention_months`.
Schedule::command('model:prune', ['--model' => [AiConversation::class, AiPendingAction::class, AiUsage::class]])->dailyAt('02:30')->onOneServer();

// Till health (module 2.7): online/offline, versions, sync and clock per till; raises and clears health alerts.
Schedule::command('till-health:refresh')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10);

// Owner alerts (module 7.8): urgent emails two minutes after each health refresh; the daily digest at 07:00 London;
// bell entries after 90 days.
Schedule::command('alerts:check')->cron('2-59/5 * * * *')->onOneServer()->withoutOverlapping(10);
Schedule::command('alerts:digest')->dailyAt('07:00')->timezone('Europe/London')->onOneServer()->withoutOverlapping(60);
Schedule::command('model:prune', ['--model' => [AlertNotification::class]])->dailyAt('03:15')->onOneServer();

// Reporting tables (module 3.1): re-queue shop-days whose rebuild job was lost or failed (pushes queue their own).
Schedule::command('reports:process-dirty')->everyMinute()->onOneServer()->withoutOverlapping(10);

// Privacy (module 7.7): count customers past each business's data retention; anonymise only where the owner turned
// on automatic anonymising (the rest stay a dry run).
Schedule::command('privacy:retention', ['--apply'])->dailyAt('03:30')->onOneServer()->withoutOverlapping();

// Shelf labels (gap #6): queue labels for offers that start today or ended yesterday.
Schedule::command('labels:queue-offers')->dailyAt('00:10')->timezone('Europe/London')->onOneServer()->withoutOverlapping(60);

// Admin dashboard (module 1.9): a heartbeat so System health can show the scheduler is running.
Schedule::call(fn () => SchedulerHeartbeat::beat())->everyMinute()->name('scheduler:heartbeat')->onOneServer();

// Invoice import (module 6.5): uploaded files go after 90 days, the import rows after 24 months.
Schedule::call(fn () => app(PurgeInvoiceImportFiles::class)->handle())->name('invoice-imports:purge-files')->dailyAt('03:40')->onOneServer();
Schedule::command('model:prune', ['--model' => [InvoiceImport::class]])->dailyAt('03:45')->onOneServer();
