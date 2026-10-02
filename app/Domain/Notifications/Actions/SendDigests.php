<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Ai\MorningSummary\Actions\BuildMorningSummary;
use App\Domain\Ai\MorningSummary\Data\CompanyFacts;
use App\Domain\Ai\MorningSummary\Queries\MorningFacts;
use App\Domain\Mail\Data\OwnerDigestData;
use App\Domain\Mail\Mailables\OwnerDigestMail;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Notifications\Data\Recipient;
use App\Domain\Notifications\Enums\AlertDelivery;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Models\AlertDispatch;
use App\Domain\Notifications\Queries\DigestFindings;
use App\Domain\Notifications\Queries\DigestSections;
use App\Domain\Notifications\Support\AlertInbox;
use App\Domain\Notifications\Support\AlertLinks;
use App\Domain\Notifications\Support\AlertRecipients;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * The daily alert digest (module 7.8), run by `alerts:digest` at 07:00 London. For every business that is trading
 * (not suspended or cancelled) and every member with at least one type on "daily digest": one queued email and one
 * bell entry when there is something to say, never twice for the same London day (an `alert_dispatches` row).
 *
 * The morning summary (module 6.3) rides in the same email for users who have it on: one email per user per morning.
 * Its facts are computed once per business, only when someone wants it.
 */
class SendDigests
{
    public function __construct(
        private readonly DigestFindings $findings,
        private readonly MorningFacts $morningFacts,
        private readonly BuildMorningSummary $morningSummary,
    ) {}

    /**
     * @param  list<string>|null  $companyIds  Only these businesses; null = all.
     * @return array{companies: int, sent: int, empty: int, already: int}
     */
    public function handle(?CarbonImmutable $now = null, ?array $companyIds = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $day = TradingDay::today($now)->format('Y-m-d');
        $totals = ['companies' => 0, 'sent' => 0, 'empty' => 0, 'already' => 0];

        Company::query()
            ->whereNotIn('status', [CompanyStatus::Suspended->value, CompanyStatus::Cancelled->value])
            ->when($companyIds !== null, fn ($q) => $q->whereIn('id', $companyIds ?? []))
            ->chunkById(100, function ($companies) use ($now, $day, &$totals) {
                foreach ($companies as $company) {
                    $this->company($company, $now, $day, $totals);
                }
            });

        return $totals;
    }

    /**
     * @param  array{companies: int, sent: int, empty: int, already: int}  $totals
     */
    private function company(Company $company, CarbonImmutable $now, string $day, array &$totals): void
    {
        $key = 'digest|'.$day;
        $already = AlertDispatch::withoutCompanyScope()->where('company_id', $company->id)->where('subject_key', $key)->pluck('user_id')->all();
        $recipients = array_values(array_filter(
            AlertRecipients::for($company->id),
            fn (Recipient $r) => ! in_array($r->userId, $already, true) && $r->getsDigest(),
        ));
        $totals['already'] += count($already);

        if ($recipients === []) {
            return;
        }

        $types = array_values(array_filter(AlertType::cases(), function (AlertType $type) use ($recipients) {
            foreach ($recipients as $recipient) {
                if ($recipient->inDigest($type)) {
                    return true;
                }
            }

            return false;
        }));
        $findings = $this->findings->for($company, $types, $now);
        $totals['companies']++;
        $facts = null;

        foreach ($recipients as $recipient) {
            $sections = DigestSections::for($recipient, $company->id, $findings);
            $summary = null;

            if ($recipient->delivery(AlertType::MorningSummary) === AlertDelivery::Digest) {
                $summary = $this->summary($company, $facts, $recipient, $now);
            }

            if ($sections === [] && $summary === null) {
                $totals['empty']++;

                continue;
            }

            Mail::to($recipient->email, $recipient->name)->queue(new OwnerDigestMail(new OwnerDigestData(
                businessName: $company->name,
                recipientName: $recipient->name,
                day: $day,
                sections: $sections,
                settingsUrl: AlertLinks::settings(),
                unsubscribeUrl: AlertLinks::unsubscribe($company->id, $recipient->userId, AlertLinks::DIGEST),
                companyId: $company->id,
                summary: $summary,
            )));

            AlertInbox::add($company->id, $recipient->userId, AlertLinks::DIGEST, 'info',
                $summary !== null
                    ? 'Morning summary: '.MailFormat::money($summary['total']['sales']).' sales yesterday'.($sections !== [] ? ', '.MailFormat::count(count($sections), 'thing').' to check' : '')
                    : 'Daily summary: '.MailFormat::count(count($sections), 'thing').' to check',
                $sections !== [] ? implode(' · ', array_column($sections, 'title')) : 'How '.$summary['scope'].' traded on '.$summary['dayLabel'],
                $summary['url'] ?? $sections[0]['url']);

            AlertDispatch::withoutCompanyScope()->create([
                'company_id' => $company->id, 'user_id' => $recipient->userId, 'alert_type' => AlertLinks::DIGEST,
                'subject_key' => 'digest|'.$day, 'state' => AlertDispatch::SENT, 'notified_at' => $now,
            ]);
            $totals['sent']++;
        }
    }

    /**
     * The user's morning summary, or null when there is nothing to say. The business's facts are computed on first
     * use. Never stops the digest going out.
     *
     * @return array<string, mixed>|null
     */
    private function summary(Company $company, ?CompanyFacts &$facts, Recipient $recipient, CarbonImmutable $now): ?array
    {
        try {
            $facts ??= $this->morningFacts->for($company, $now);

            return $this->morningSummary->handle($company, $facts, $recipient);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
