<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Mail\Support\EmailTemplates;
use App\Domain\Shared\Support\TableQuery;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Email log (module 1.7): every outgoing email, newest first, with search and filters. Owner and support only
 * (EmailLogPolicy via route "can" middleware).
 */
class EmailLogController extends Controller
{
    private const TIMEZONE = 'Europe/London';

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'template' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::enum(EmailStatus::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $query = EmailLog::query()->with('company:id,name');
        $this->applyFilters($query, $filters);

        $logs = TableQuery::from($request)
            ->searchable(['to', 'subject'])
            ->sortable(['created_at', 'sent_at', 'to', 'template', 'status'])
            ->defaultSort('created_at', 'desc')
            ->paginate($query, fn (EmailLog $log) => $this->row($log));

        return Inertia::render('admin/emails/index', [
            'logs' => $logs,
            'filters' => [
                'template' => $filters['template'] ?? null,
                'status' => $filters['status'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'templateOptions' => $this->templateOptions(),
            'statusOptions' => EmailStatus::options(),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * @param  Builder<EmailLog>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (filled($filters['template'] ?? null)) {
            $query->where('template', $filters['template']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        // Dates are picked as UK calendar days and compared in UTC.
        if (filled($filters['from'] ?? null)) {
            $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', (string) $filters['from'], self::TIMEZONE)?->startOfDay()->utc());
        }

        if (filled($filters['to'] ?? null)) {
            $query->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', (string) $filters['to'], self::TIMEZONE)?->endOfDay()->utc());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(EmailLog $log): array
    {
        return [
            'id' => $log->id,
            'to' => $log->to,
            'template' => $log->template,
            'templateLabel' => EmailTemplates::label($log->template),
            'subject' => $log->subject,
            'company' => $log->company === null ? null : ['id' => $log->company->id, 'name' => $log->company->name],
            'status' => $log->status->value,
            'error' => $log->error,
            'messageId' => $log->message_id,
            'mailable' => class_basename($log->mailable),
            'meta' => $log->meta ?? (object) [],
            'isTest' => (bool) ($log->meta['test'] ?? false),
            'createdAt' => $log->created_at?->toIso8601ZuluString(),
            'sentAt' => $log->sent_at?->toIso8601ZuluString(),
            'updatedAt' => $log->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Branded templates first, then any other template key found in the log (framework notifications).
     *
     * @return list<array{value: string, label: string}>
     */
    private function templateOptions(): array
    {
        $keys = EmailTemplates::keys();

        $others = EmailLog::query()->whereNotIn('template', $keys)->distinct()->orderBy('template')->limit(50)->pluck('template')->all();

        return array_map(
            fn (string $key) => ['value' => $key, 'label' => EmailTemplates::label($key)],
            array_values(array_merge($keys, $others)),
        );
    }

    /**
     * @return array{sent: int, failed: int, queued: int}
     */
    private function summary(): array
    {
        $since = now()->subDays(30);

        return [
            'sent' => EmailLog::query()->where('status', EmailStatus::Sent)->where('created_at', '>=', $since)->count(),
            'failed' => EmailLog::query()->where('status', EmailStatus::Failed)->where('created_at', '>=', $since)->count(),
            'queued' => EmailLog::query()->where('status', EmailStatus::Queued)->count(),
        ];
    }
}
