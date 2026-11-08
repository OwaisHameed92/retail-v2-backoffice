<?php

namespace App\Domain\Operations\Support;

/**
 * A plain-English label for a scheduled task (ScheduledTaskName) on the admin Sync & jobs page, so staff read
 * "Billing run" instead of "billing:run". A task not listed here shows its command name.
 */
final class ScheduledTaskLabel
{
    private const LABELS = [
        'alerts:check' => 'Check till alerts',
        'alerts:digest' => 'Daily alerts email',
        'anomalies:detect' => 'Spot unusual sales (hourly)',
        'anomalies:detect --daily' => 'Spot unusual sales (daily)',
        'billing:reconcile-gocardless' => 'GoCardless payment check',
        'billing:run' => 'Billing run (invoices, Direct Debits, reminders)',
        'invoice-imports:purge-files' => 'Delete old supplier invoice files',
        'labels:queue-offers' => 'Queue shelf labels for offers',
        'licence:keys:prune' => 'Tidy old licence keys',
        'licences:refresh' => 'Refresh licence statuses',
        'model:prune --model=AiConversation --model=AiPendingAction --model=AiUsage' => 'Clear old assistant chats',
        'model:prune --model=AlertNotification' => 'Clear old alert notifications',
        'model:prune --model=Anomaly' => 'Clear old unusual-sales alerts',
        'model:prune --model=EmailLog' => 'Clear old email log',
        'model:prune --model=InvoiceImport' => 'Clear old supplier invoice imports',
        'model:prune --model=MorningSummary' => 'Clear old morning summaries',
        'model:prune --model=ScheduledTaskRun' => 'Clear old task run history',
        'online-store:expire-payments' => 'Online store: expire unpaid payments',
        'online-store:housekeeping' => 'Online store: housekeeping',
        'partners:send-webhooks' => 'Send partner webhooks',
        'pharmacy:dmd-fetch' => 'Fetch NHS dm+d release',
        'privacy:retention --apply' => 'Privacy: delete data past retention',
        'reports:process-dirty' => 'Rebuild reports after till pushes',
        'scheduler:heartbeat' => 'Scheduler heartbeat',
        'till-health:refresh' => 'Refresh till health',
        'workshop:send-webhooks' => 'Send BikeBook webhooks',
    ];

    public static function for(string $task): string
    {
        return self::LABELS[$task] ?? $task;
    }
}
