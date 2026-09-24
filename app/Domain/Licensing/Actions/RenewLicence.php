<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Licensing\Data\RenewedLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Licensing\Support\LicenceMailer;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renews one licence: +1 month, +1 year (from its current end when that is still ahead, so no days are lost)
 * or until a date; always to the end of that day, Europe/London. A trial becomes a paid licence (active), grace
 * and expired licences are active again. Suspended licences stay suspended; issued ones stay issued until
 * activation. Grace days switch to the plan's paid grace. The company's owners get the "licences renewed" email.
 */
class RenewLicence
{
    public function __construct(
        private readonly RecordAudit $audit,
        private readonly LicenceMailer $mailer,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Licence $licence, RenewalTerm $term, bool $notify = true): RenewedLicences
    {
        return DB::transaction(function () use ($licence, $term, $notify) {
            $now = CarbonImmutable::now();
            $licence = $this->apply(LicenceGuard::lock($licence), $term, $now);
            /** @var CarbonImmutable $expiresAt */
            $expiresAt = $licence->expires_at;

            $emailed = $notify && $licence->company !== null ? $this->mailer->renewed($licence->company, [$licence], $expiresAt) : 0;

            return new RenewedLicences([$licence], $expiresAt, $emailed);
        });
    }

    /**
     * Renew a licence already locked by the caller (also used by RenewCompanyLicences). No email.
     *
     * @throws ValidationException
     */
    public function apply(Licence $licence, RenewalTerm $term, CarbonImmutable $now, ?CarbonImmutable $expiresAt = null): Licence
    {
        LicenceGuard::ensureNotRevoked($licence, 'renew it');

        $expiresAt ??= $term->expiryFrom(LicenceTerms::endsAt($licence), $now);

        if ($expiresAt->lessThanOrEqualTo($now)) {
            throw ValidationException::withMessages(['until' => 'Choose a date after today.']);
        }

        $before = [
            'status' => $licence->status->value,
            'expires_at' => $licence->expires_at?->toIso8601String(),
            'grace_days' => $licence->grace_days,
        ];

        $licence->expires_at = $expiresAt;
        $licence->grace_days = $licence->plan !== null ? $licence->plan->grace_days : $licence->grace_days;
        $licence->status = LicenceTerms::storedStatusAfterDateChange($licence, $now);
        $licence->save();

        $this->audit->handle('licence.renewed', $licence, $before, [
            'status' => $licence->status->value,
            'expires_at' => $expiresAt->toIso8601String(),
            'grace_days' => $licence->grace_days,
        ], ['term' => $term->period->value]);

        return $licence;
    }
}
