<?php

namespace App\Domain\Operations\Support;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;

/**
 * The stable name of a scheduled task, shared by the run recorder and the admin list so they match: the artisan
 * command without the PHP binary and artisan path ("billing:run", "model:prune --model=EmailLog"), or a callback's
 * `->name()`. Class names are shortened to their basename. Never longer than the `scheduled_task_runs.task` column.
 */
final class ScheduledTaskName
{
    public const MAX = 191;

    public static function of(Event $event): string
    {
        if ($event instanceof CallbackEvent) {
            $name = (string) ($event->description ?: $event->getSummaryForDisplay());
        } else {
            $name = (string) preg_replace('/^.*?["\']?artisan["\']?\s+/', '', trim((string) $event->command), 1);
            // 'App\Domain\Mail\Models\EmailLog' → EmailLog; plain quoted values lose their quotes.
            $name = (string) preg_replace('/["\']?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+([A-Za-z_][A-Za-z0-9_]*)["\']?/', '$1', $name);
            $name = (string) preg_replace('/(["\'])([^"\'\s]*)\1/', '$2', $name);
        }

        $name = trim((string) preg_replace('/\s+/', ' ', $name));

        return Str::limit($name === '' ? 'Unnamed task' : $name, self::MAX, '');
    }
}
