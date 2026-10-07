<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Mail\Data\PlanChangedData;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

/**
 * Sent to the owners when an admin changes the business's plan (Admin → business → Billing → Change plan). A notice:
 * held while "Reminders and notices" are not sent automatically (P11).
 */
final class PlanChangedMail extends BrandedMailable
{
    public function __construct(public PlanChangedData $data) {}

    public static function templateKey(): string
    {
        return 'plan-changed';
    }

    public static function templateLabel(): string
    {
        return 'Plan changed';
    }

    public static function templateDescription(): string
    {
        return 'Sent when we move a business to another plan: what changes for its features, fees and licences.';
    }

    public static function sample(): static
    {
        $manual = ManualCollection::active();

        return new self(new PlanChangedData(
            businessName: 'Khan Mini Mart',
            ownerName: 'Aisha Khan',
            fromPlan: 'Setup only',
            toPlan: 'Setup + monthly',
            changedOn: now()->startOfDay(),
            changes: [
                'New features: Loyalty, Promotions.',
                'Monthly fee: '.MailFormat::money('30.00').' a month for 1 till, starting today.',
                'Your tills stay valid until '.MailFormat::date(now()->addMonth()->subDay()).' and renew as each month is paid.',
            ],
            setupFee: null,
            directDebitUrl: $manual ? null : config('sspos.portal_url').'/app/billing',
            directDebitBy: $manual ? null : now()->addDays(3),
            howToPay: $manual ? 'We email you an invoice each month. Pay it by '.ManualCollection::methodsText().', quoting the invoice number as the reference.' : null,
        ));
    }

    public function emailCategory(): EmailCategory
    {
        return EmailCategory::Reminders;
    }

    public function subjectLine(): string
    {
        return 'Your Switch & Save plan has changed to '.$this->data->toPlan;
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return [
            'business' => $this->data->businessName,
            'from_plan' => $this->data->fromPlan,
            'to_plan' => $this->data->toPlan,
            'setup_fee' => $this->data->setupFee,
            'invoice' => $this->data->setupInvoice,
        ];
    }

    public function content(): Content
    {
        $facts = array_filter([
            'Business' => $this->data->businessName,
            'Plan before' => $this->data->fromPlan,
            'Plan now' => $this->data->toPlan,
            'Changed on' => MailFormat::date($this->data->changedOn),
            'Setup fee' => $this->data->setupFee !== null ? MailFormat::money($this->data->setupFee).($this->data->setupInvoice !== null ? ' ('.$this->data->setupInvoice.')' : '') : null,
        ], fn ($value) => $value !== null);

        return new Content(markdown: 'mail.plan-changed', with: [
            'firstName' => MailFormat::firstName($this->data->ownerName),
            'facts' => $facts,
            'directDebitBy' => $this->data->directDebitBy !== null ? MailFormat::date($this->data->directDebitBy) : null,
            'portalUrl' => config('sspos.portal_url').'/login',
        ]);
    }
}
