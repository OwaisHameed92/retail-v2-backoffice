<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Mail\Actions\SendHeldEmail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Mail\Models\HeldEmail;
use App\Domain\Mail\Support\EmailControl;
use Illuminate\Validation\ValidationException;

/**
 * The invoice's "Email to customer" / "Send again" button (P11: an admin's own send, never held). When emails of
 * this invoice are held, those are sent (rebuilt from the invoice as it is now), so the customer never gets it twice;
 * otherwise it is sent to the billing emails or owners (SendInvoice). Audited either way. Returns how many went.
 */
class EmailInvoice
{
    public function __construct(
        private readonly SendInvoice $sendInvoice,
        private readonly SendHeldEmail $sendHeld,
    ) {}

    /** @throws ValidationException */
    public function handle(Invoice $invoice): int
    {
        $held = self::held($invoice);

        if ($held !== []) {
            foreach ($held as $email) {
                $this->sendHeld->handle($email);
            }

            return count($held);
        }

        return EmailControl::manually(fn () => $this->sendInvoice->handle($invoice));
    }

    /**
     * Held emails of this invoice, oldest first.
     *
     * @return list<HeldEmail>
     */
    public static function held(Invoice $invoice): array
    {
        if ($invoice->number === null) {
            return [];
        }

        $logs = EmailLog::query()->where('company_id', $invoice->company_id)->where('template', InvoiceMail::templateKey())
            ->where('meta->invoice', $invoice->number)->select('id');

        return HeldEmail::query()->waiting()->where('company_id', $invoice->company_id)->whereIn('email_log_id', $logs)
            ->orderBy('created_at')->get()->all();
    }
}
