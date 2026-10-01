<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Mail\Data\OwnerAlertData;
use App\Domain\Mail\Mailables\OwnerAlertMail;
use App\Domain\Mail\Mailables\OwnerAlertResolvedMail;
use App\Domain\Notifications\Data\Recipient;
use App\Domain\Notifications\Data\UrgentSubject;
use App\Domain\Notifications\Enums\AlertDelivery;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Models\AlertDispatch;
use App\Domain\Notifications\Queries\UrgentSubjects;
use App\Domain\Notifications\Support\AlertInbox;
use App\Domain\Notifications\Support\AlertLinks;
use App\Domain\Notifications\Support\AlertRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Urgent owner alerts (module 7.8), run every 5 minutes by `alerts:check` after `till-health:refresh`.
 *
 * For each open till-offline / sync problem and each member who wants that type for that shop: a bell entry, plus an
 * email for "straight away" users. Never twice for the same problem while it stays open, and not again when it clears
 * and comes back within {@see self::REPEAT_HOURS} hours of the last one (the row is `muted`). When a problem a user
 * was told about clears: a "resolved" bell entry and email, unless it cleared only because the licence or business
 * stopped trading. All mail is queued and logged (BrandedMailable).
 */
class DispatchUrgentAlerts
{
    public const REPEAT_HOURS = 6;

    private const MUTED = 'muted';

    /**
     * @param  list<string>|null  $companyIds  Only these businesses (tests); null = all.
     * @return array{emailed: int, notified: int, resolved: int, muted: int}
     */
    public function handle(?CarbonImmutable $now = null, ?array $companyIds = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $open = UrgentSubjects::open($companyIds);
        $tracked = AlertDispatch::withoutCompanyScope()->whereIn('state', [AlertDispatch::OPEN, self::MUTED])
            ->whereIn('alert_type', [AlertType::TillOffline->value, AlertType::SyncFailing->value])
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds ?? []))
            ->distinct()->pluck('company_id')->all();
        $totals = ['emailed' => 0, 'notified' => 0, 'resolved' => 0, 'muted' => 0];

        foreach (array_unique([...array_keys($open), ...$tracked]) as $companyId) {
            $recipients = AlertRecipients::for((string) $companyId);
            $dispatches = AlertDispatch::withoutCompanyScope()->where('company_id', $companyId)
                ->whereIn('alert_type', [AlertType::TillOffline->value, AlertType::SyncFailing->value])->get()
                ->keyBy(fn (AlertDispatch $d) => $d->user_id.'#'.$d->subject_key);

            foreach ($open[$companyId] ?? [] as $subject) {
                foreach ($recipients as $recipient) {
                    if ($recipient->wants($subject->type) && $recipient->covers($subject->branchId)) {
                        $this->raise($subject, $recipient, $dispatches->get($recipient->userId.'#'.$subject->key), $now, $totals);
                    }
                }
            }

            $this->clear((string) $companyId, $recipients, $dispatches->all(), array_keys($open[$companyId] ?? []), $now, $totals);
        }

        return $totals;
    }

    /**
     * @param  array{emailed: int, notified: int, resolved: int, muted: int}  $totals
     */
    private function raise(UrgentSubject $subject, Recipient $recipient, ?AlertDispatch $dispatch, CarbonImmutable $now, array &$totals): void
    {
        if ($dispatch !== null && $dispatch->state !== AlertDispatch::RESOLVED) {
            return; // already told while it stays open
        }

        if ($dispatch !== null && $dispatch->notified_at !== null && $dispatch->notified_at->greaterThan($now->subHours(self::REPEAT_HOURS))) {
            $dispatch->forceFill(['state' => self::MUTED, 'resolved_at' => null])->save();
            $totals['muted']++;

            return;
        }

        $data = $this->data($subject, $recipient);
        AlertInbox::add($subject->companyId, $recipient->userId, $subject->type, 'danger', $data->headline(), $subject->summary, $data->url);
        $totals['notified']++;

        if ($recipient->delivery($subject->type) === AlertDelivery::Immediate) {
            Mail::to($recipient->email, $recipient->name)->queue(new OwnerAlertMail($data));
            $totals['emailed']++;
        }

        AlertDispatch::withoutCompanyScope()->updateOrCreate(
            ['company_id' => $subject->companyId, 'user_id' => $recipient->userId, 'subject_key' => $subject->key],
            ['alert_type' => $subject->type->value, 'state' => AlertDispatch::OPEN, 'notified_at' => $now, 'resolved_at' => null],
        );
    }

    /**
     * Rows still open or muted whose problem is gone.
     *
     * @param  list<Recipient>  $recipients
     * @param  array<string, AlertDispatch>  $dispatches
     * @param  list<string>  $openKeys
     * @param  array{emailed: int, notified: int, resolved: int, muted: int}  $totals
     */
    private function clear(string $companyId, array $recipients, array $dispatches, array $openKeys, CarbonImmutable $now, array &$totals): void
    {
        $gone = array_filter($dispatches, fn (AlertDispatch $d) => $d->state !== AlertDispatch::RESOLVED && ! in_array($d->subject_key, $openKeys, true));

        if ($gone === []) {
            return;
        }

        $subjects = UrgentSubjects::cleared($companyId, array_values(array_unique(array_map(fn (AlertDispatch $d) => $d->subject_key, $gone))));
        $byUser = [];
        foreach ($recipients as $recipient) {
            $byUser[$recipient->userId] = $recipient;
        }

        foreach ($gone as $dispatch) {
            $subject = $subjects[$dispatch->subject_key] ?? null;
            $recipient = $byUser[$dispatch->user_id] ?? null;

            if ($dispatch->state === AlertDispatch::OPEN && $subject !== null && $subject->genuinelyResolved && $recipient !== null && $recipient->wants($subject->type)) {
                $data = $this->data($subject, $recipient, $subject->resolvedAt ?? $now);
                AlertInbox::add($companyId, $recipient->userId, $subject->type, 'success', $data->headline(), null, $data->url);

                if ($recipient->delivery($subject->type) === AlertDelivery::Immediate) {
                    Mail::to($recipient->email, $recipient->name)->queue(new OwnerAlertResolvedMail($data));
                    $totals['emailed']++;
                }

                $totals['resolved']++;
            }

            $dispatch->forceFill(['state' => AlertDispatch::RESOLVED, 'resolved_at' => $now])->save();
        }
    }

    private function data(UrgentSubject $subject, Recipient $recipient, ?CarbonImmutable $resolvedAt = null): OwnerAlertData
    {
        return new OwnerAlertData(
            businessName: $subject->businessName,
            recipientName: $recipient->name,
            type: $subject->type->value,
            problem: $subject->problem,
            shopName: $subject->shopName,
            tillName: $subject->tillName,
            summary: $subject->summary,
            since: $subject->since,
            url: AlertLinks::forType($subject->type, $subject->branchId),
            unsubscribeUrl: AlertLinks::unsubscribe($subject->companyId, $recipient->userId, $subject->type),
            settingsUrl: AlertLinks::settings(),
            resolvedAt: $resolvedAt,
            companyId: $subject->companyId,
        );
    }
}
