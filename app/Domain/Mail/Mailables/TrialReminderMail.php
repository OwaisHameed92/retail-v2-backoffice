<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\TrialReminderData;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

/**
 * Reminder near the end of the free trial (day 5 of 7 by default, module 1.6 schedules it).
 */
final class TrialReminderMail extends BrandedMailable
{
    public function __construct(public TrialReminderData $data) {}

    public static function templateKey(): string
    {
        return 'trial-reminder';
    }

    public static function templateLabel(): string
    {
        return 'Trial ending soon';
    }

    public static function templateDescription(): string
    {
        return 'Sent a couple of days before the free trial ends: what happens next and how to pay.';
    }

    public static function sample(): static
    {
        return new self(new TrialReminderData(
            businessName: 'Khan Mini Mart',
            ownerName: 'Aisha Khan',
            daysLeft: 2,
            trialEndsAt: now()->addDays(2)->startOfDay()->addHours(9),
            tillCount: 3,
            priceSummary: MailFormat::money('25').' per till per month',
        ));
    }

    public function subjectLine(): string
    {
        return 'Your free trial '.$this->endsIn();
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'days_left' => $this->data->daysLeft, 'tills' => $this->data->tillCount];
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.trial-reminder', with: [
            'firstName' => MailFormat::firstName($this->data->ownerName),
            'endsIn' => $this->endsIn(),
            'endsOn' => MailFormat::date($this->data->trialEndsAt),
            'tillCount' => MailFormat::count($this->data->tillCount, 'till'),
            'portalUrl' => config('sspos.portal_url').'/login',
        ]);
    }

    /** "ends today", "ends tomorrow", "ends in 3 days" */
    private function endsIn(): string
    {
        return match (true) {
            $this->data->daysLeft <= 0 => 'ends today',
            $this->data->daysLeft === 1 => 'ends tomorrow',
            default => 'ends in '.$this->data->daysLeft.' days',
        };
    }
}
