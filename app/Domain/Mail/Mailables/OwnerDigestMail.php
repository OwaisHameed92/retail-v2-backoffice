<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\OwnerDigestData;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Mail\Support\MorningSummaryMail;
use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Headers;

/**
 * The 07:00 daily alert digest (module 7.8): tills and sync, low stock, cash variances, compliance and sync
 * conflicts, each with a link to the screen and its own unsubscribe link, led by the morning summary (module 6.3:
 * yesterday's sales, movers, things to watch and a short AI paragraph) for users who have it on. One email per user
 * per morning; not sent when there is nothing to say.
 */
final class OwnerDigestMail extends BrandedMailable
{
    public function __construct(public OwnerDigestData $data) {}

    public static function templateKey(): string
    {
        return 'owner-digest';
    }

    public static function templateLabel(): string
    {
        return 'Owner morning summary and daily digest';
    }

    public static function templateDescription(): string
    {
        return 'Sent at 07:00 to portal users: the morning summary of yesterday\'s trading (with a short AI-written paragraph when AI is set up) and the daily digest of tills, stock, cash, compliance and sync conflicts.';
    }

    public static function sample(): static
    {
        $portal = config('sspos.portal_url');
        $settings = $portal.'/app/settings/notifications';

        return new self(new OwnerDigestData(
            businessName: 'Khan Mini Mart',
            recipientName: 'Aisha Khan',
            day: CarbonImmutable::now(Country::zone())->format('Y-m-d'),
            sections: [
                ['type' => 'lowStock', 'title' => 'Low and negative stock', 'summary' => '14 products low, 3 out of stock, 1 below zero.',
                    'items' => ['Leeds: Coca-Cola 500ml, -2 on hand', 'Leeds: Walkers Ready Salted 32.5g, 0 on hand', 'Bradford: Warburtons Toastie 800g, 3 on hand (low at 6)'],
                    'more' => 15, 'url' => $portal.'/app/stock?status=low', 'unsubscribeUrl' => $settings],
                ['type' => 'cashVariance', 'title' => 'Cash variances', 'summary' => '1 difference over your alert amount yesterday.',
                    'items' => ['Leeds, Till 1: Cash £12.40 short'], 'more' => 0, 'url' => $portal.'/app/cash/alerts', 'unsubscribeUrl' => $settings],
                ['type' => 'compliance', 'title' => 'Compliance expiries and recalls', 'summary' => '1 licence expiring, 1 open recall.',
                    'items' => ['Premises licence PL-2231 · Leeds expires 18 Nov 2026 (9 days)', 'Recall: Hovis Seed Sensations batch L2291'],
                    'more' => 0, 'url' => $portal.'/app/compliance', 'unsubscribeUrl' => $settings],
            ],
            settingsUrl: $settings,
            unsubscribeUrl: $settings,
            summary: MorningSummaryMail::sample($portal, $settings),
        ));
    }

    public function subjectLine(): string
    {
        $count = count($this->data->sections);
        $summary = $this->data->summary;

        if ($summary !== null) {
            return 'Your morning summary for '.$this->data->businessName.': '.MailFormat::money((string) $summary['total']['sales']).' sales yesterday'
                .($count > 0 ? ', '.MailFormat::count($count, 'thing').' to check' : '');
        }

        return 'Your daily summary for '.$this->data->businessName.': '.MailFormat::count($count, 'thing').' to check';
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'day' => $this->data->day, 'sections' => implode(',', array_column($this->data->sections, 'type')),
            'summary' => $this->data->summary !== null ? (($this->data->summary['narrative'] ?? null) !== null ? 'withNarrative' : 'figuresOnly') : 'none'];
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->data->unsubscribeUrl.'>']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.owner-digest', with: [
            'firstName' => MailFormat::firstName($this->data->recipientName),
            'date' => MailFormat::date(CarbonImmutable::parse($this->data->day, Country::zone())),
            'summary' => $this->data->summary !== null ? MorningSummaryMail::lines($this->data->summary) : null,
        ]);
    }
}
