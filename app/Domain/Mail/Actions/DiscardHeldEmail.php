<?php

namespace App\Domain\Mail\Actions;

use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Mail\Models\HeldEmail;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An admin decides a held email (P11) is not to be sent: the held copy is cleared, the log row says "Discarded" and
 * it is audited (`email.held_discarded`). Nothing is sent. A discarded welcome email's keys can still be sent with
 * "Resend key" on each licence (that makes new keys).
 */
class DiscardHeldEmail
{
    public function __construct(private readonly RecordAudit $audit) {}

    /** @throws ValidationException */
    public function handle(HeldEmail $held): HeldEmail
    {
        return DB::transaction(function () use ($held) {
            $held = HeldEmail::query()->lockForUpdate()->findOrFail($held->id);

            if (! $held->isWaiting()) {
                throw ValidationException::withMessages(['email' => 'This email was already '.$held->status.'.']);
            }

            $now = CarbonImmutable::now();

            $held->forceFill([
                'status' => HeldEmail::DISCARDED,
                'payload' => null,
                'actioned_by_admin_id' => auth('admin')->id(),
                'actioned_at' => $now,
            ])->save();

            if ($held->email_log_id !== null) {
                EmailLog::query()->whereKey($held->email_log_id)->update(['status' => EmailStatus::Discarded->value, 'updated_at' => $now]);
            }

            $this->audit->handle('email.held_discarded', $held, ['status' => HeldEmail::HELD], ['status' => HeldEmail::DISCARDED], [
                'template' => $held->template,
                'to' => $held->to,
                'subject' => $held->subject,
            ], companyId: $held->company_id);

            return $held;
        });
    }
}
