<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\IssuedLicence;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceMailer;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Resend key e-mail" (module 1.11, contract §17.15.3 "re-send it"). Plain keys are never stored, so the key is
 * replaced (ReissueKey: the old key stops working, a bound PC is released, the new key gets a new activate-by
 * window) and the new one is emailed to every active owner. Nothing changes when there is no owner to email.
 */
class ResendLicenceKey
{
    public function __construct(
        private readonly ReissueKey $reissue,
        private readonly LicenceMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return array{issued: IssuedLicence, emailed: int}
     *
     * @throws ValidationException
     */
    public function handle(Licence $licence): array
    {
        /** @var Company|null $company */
        $company = Company::withTrashed()->find($licence->company_id);

        if ($company === null || $company->trashed() || $company->isCancelled()) {
            throw ValidationException::withMessages(['licence' => 'The business is cancelled, so keys cannot be sent.']);
        }

        if ($this->mailer->owners($company)->isEmpty()) {
            throw ValidationException::withMessages(['licence' => "{$company->name} has no active owner to email. Add an owner first."]);
        }

        return DB::transaction(function () use ($licence, $company) {
            $issued = $this->reissue->handle($licence);
            $emailed = $this->mailer->keys($company, [$issued], replacesOldKey: true);

            $this->audit->handle('licence.key_emailed', $issued->licence, null, null, ['owners' => $emailed, 'key_last4' => $issued->licence->key_last4, 'resent' => true]);

            return ['issued' => $issued, 'emailed' => $emailed];
        });
    }
}
