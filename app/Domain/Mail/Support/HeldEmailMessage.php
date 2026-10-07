<?php

namespace App\Domain\Mail\Support;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Mail\Data\SetPasswordData;
use App\Domain\Mail\Mailables\BrandedMailable;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Mail\Models\HeldEmail;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * The message a held email (P11) sends, as it would go now:
 *
 * - an invoice: rebuilt from the invoice as it is now (status, amount due), to the same person;
 * - a first set-password link: a new 7-day link (the held one may have run out);
 * - anything else: exactly what was held (the welcome email keeps its licence keys, which exist nowhere else).
 *
 * For the preview the password link is not made: a placeholder stands in for it.
 */
final class HeldEmailMessage
{
    public function __construct(private readonly BillingMailer $billingMailer) {}

    /** @throws ValidationException */
    public function forSending(HeldEmail $held): BrandedMailable
    {
        return $this->build($held, preview: false);
    }

    /** @throws ValidationException */
    public function forPreview(HeldEmail $held): BrandedMailable
    {
        return $this->build($held, preview: true);
    }

    /** @throws ValidationException */
    private function build(HeldEmail $held, bool $preview): BrandedMailable
    {
        $mailable = $held->mailable();

        if ($mailable === null) {
            throw ValidationException::withMessages(['email' => 'This email cannot be read any more (it was sent, discarded, or the app key changed). Discard it.']);
        }

        $fresh = match (true) {
            $mailable instanceof InvoiceMail => $this->invoice($mailable),
            $mailable instanceof SetPasswordMail && $mailable->data->firstTime => $this->passwordLink($mailable, $preview),
            default => $mailable,
        };

        if ($fresh !== $mailable && $fresh->to === []) {
            foreach ($mailable->to as $recipient) {
                $fresh->to($recipient['address'], $recipient['name'] ?? null);
            }
        }

        $fresh->emailLogId = $held->email_log_id;
        $fresh->manualSend = true;
        $fresh->holdChecked = true;

        return $fresh;
    }

    /** @throws ValidationException */
    private function invoice(InvoiceMail $mailable): InvoiceMail
    {
        $invoice = $mailable->data->pdfKey !== null ? Invoice::withoutCompanyScope()->with(['company', 'lines'])->find($mailable->data->pdfKey) : null;

        if ($invoice === null || $invoice->company === null) {
            return $mailable;
        }

        if ($invoice->status === InvoiceStatus::Void) {
            throw ValidationException::withMessages(['email' => "{$invoice->number} is void, so it is not sent. Discard this email."]);
        }

        return $this->billingMailer->invoiceMail($invoice, $mailable->data->recipientName, $mailable->data->resent);
    }

    /** @throws ValidationException */
    private function passwordLink(SetPasswordMail $mailable, bool $preview): SetPasswordMail
    {
        $data = $mailable->data;

        if ($preview) {
            return new SetPasswordMail(new SetPasswordData($data->name, $data->email, config('sspos.portal_url').'/reset-password/(a-new-link-is-made-when-sent)', $data->expiresInMinutes, true, $data->businessName, $data->companyId));
        }

        $user = User::query()->where('email', $data->email)->first();

        if ($user === null) {
            throw ValidationException::withMessages(['email' => "{$data->email} has no portal login any more. Discard this email."]);
        }

        $token = Password::broker('user_setup')->createToken($user);

        return PasswordLinkMail::forUser($user, $token, firstTime: true, businessName: $data->businessName, companyId: $data->companyId);
    }
}
