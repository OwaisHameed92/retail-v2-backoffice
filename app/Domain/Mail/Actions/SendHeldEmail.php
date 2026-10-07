<?php

namespace App\Domain\Mail\Actions;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Mail\Models\HeldEmail;
use App\Domain\Mail\Support\EmailControl;
use App\Domain\Mail\Support\HeldEmailMessage;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * An admin sends a held email (P11): the message as it would go now (HeldEmailMessage) is queued on the held log row
 * (held → queued → sent), the held copy is cleared and the send is audited (`email.held_sent`). Once only: a second
 * click finds it already sent. A held invoice counts as emailed from now.
 */
class SendHeldEmail
{
    public function __construct(
        private readonly HeldEmailMessage $message,
        private readonly RecordAudit $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(HeldEmail $held): HeldEmail
    {
        return DB::transaction(function () use ($held) {
            $held = HeldEmail::query()->lockForUpdate()->findOrFail($held->id);

            if (! $held->isWaiting()) {
                throw ValidationException::withMessages(['email' => 'This email was already '.$held->status.'.']);
            }

            $mailable = $this->message->forSending($held);
            $now = CarbonImmutable::now();

            if ($held->email_log_id !== null) {
                EmailLog::query()->whereKey($held->email_log_id)->update(['status' => EmailStatus::Queued->value, 'updated_at' => $now]);
            }

            $held->forceFill([
                'status' => HeldEmail::SENT,
                'payload' => null,
                'actioned_by_admin_id' => auth('admin')->id(),
                'actioned_at' => $now,
            ])->save();

            if ($mailable instanceof InvoiceMail && $mailable->data->pdfKey !== null) {
                Invoice::withoutCompanyScope()->whereKey($mailable->data->pdfKey)->increment('sent_count', 1, ['last_sent_at' => $now]);
            }

            $this->audit->handle('email.held_sent', $held, ['status' => HeldEmail::HELD], ['status' => HeldEmail::SENT], [
                'template' => $held->template,
                'to' => $held->to,
                'subject' => $held->subject,
            ], companyId: $held->company_id);

            EmailControl::manually(fn () => Mail::queue($mailable));

            return $held;
        });
    }
}
