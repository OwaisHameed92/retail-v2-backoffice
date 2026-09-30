<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Mail\Contracts\RendersAttachment;
use App\Domain\Mail\Data\CustomerStatementData;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Support\Money;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A business's account statement to one of its own customers (module 4.4), with the PDF attached (rendered in the
 * queue worker from a key, so the queued payload holds no document). Transactional, not marketing: it needs no
 * marketing consent. Replies go to the business when it has an email address.
 */
final class CustomerStatementMail extends BrandedMailable
{
    public function __construct(public CustomerStatementData $data) {}

    public static function templateKey(): string
    {
        return 'customer-statement';
    }

    public static function templateLabel(): string
    {
        return 'Customer statement';
    }

    public static function templateDescription(): string
    {
        return 'A business emails one of its customers their account statement from the portal, with the PDF attached.';
    }

    public static function sample(): static
    {
        return new self(new CustomerStatementData(
            businessName: 'Khan Mini Mart',
            businessEmail: 'hello@khanminimart.example',
            businessPhone: '0113 496 0123',
            customerName: 'Aisha Rahman',
            period: '1 Oct – 31 Oct 2026',
            closingBalance: '42.50',
            closingPoints: 240,
        ));
    }

    public function subjectLine(): string
    {
        return "Your statement from {$this->data->businessName}";
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'period' => $this->data->period];
    }

    public function envelope(): Envelope
    {
        $envelope = parent::envelope();

        if (($email = $this->data->businessEmail) !== null && $email !== '') {
            $envelope->replyTo = [new Address($email, $this->data->businessName)];
        }

        return $envelope;
    }

    public function content(): Content
    {
        $balance = Money::normalise($this->data->closingBalance);
        $owes = Money::compare($balance, '0') > 0;
        $label = $owes ? 'Amount owed' : (Money::isNegative($balance) ? 'In credit' : 'Balance');

        return new Content(markdown: 'mail.customer-statement', with: [
            'firstName' => MailFormat::firstName($this->data->customerName),
            'owes' => $owes,
            'facts' => [
                'Period' => $this->data->period,
                $label => BillingFormat::money(ltrim($balance, '-')),
                'Points' => number_format($this->data->closingPoints),
            ],
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if ($this->data->pdfRenderer === null || $this->data->pdfKey === null) {
            return [];
        }

        $pdf = app($this->data->pdfRenderer);
        $bytes = $pdf instanceof RendersAttachment ? $pdf->renderAttachment($this->data->pdfKey) : null;

        return $bytes === null ? [] : [Attachment::fromData(fn () => $bytes, $this->data->filename)->withMime('application/pdf')];
    }
}
