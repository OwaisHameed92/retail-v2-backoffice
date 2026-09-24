<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Licensing\Data\RenewedLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceMailer;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Renew all": renews every live licence of a company's active tills (active branch, active till, not revoked)
 * to one shared expiry, so the tills stay in step. Relative terms count from the latest current end among them
 * (or now). Sends one "licences renewed" email per owner listing every till.
 */
class RenewCompanyLicences
{
    public function __construct(
        private readonly RenewLicence $renewLicence,
        private readonly LicenceMailer $mailer,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, RenewalTerm $term, bool $notify = true): RenewedLicences
    {
        return DB::transaction(function () use ($company, $term, $notify) {
            $now = CarbonImmutable::now();

            $licences = self::renewable($company)->lockForUpdate()->get();

            if ($licences->isEmpty()) {
                throw ValidationException::withMessages(['status' => "{$company->name} has no active tills with a licence to renew."]);
            }

            $latestEnd = $licences->map(fn (Licence $licence) => LicenceTerms::endsAt($licence))->filter()->max();
            $expiresAt = $term->expiryFrom($latestEnd, $now);

            $renewed = $licences->map(fn (Licence $licence) => $this->renewLicence->apply($licence, $term, $now, $expiresAt))->values()->all();

            $emailed = $notify ? $this->mailer->renewed($company, $renewed, $expiresAt) : 0;

            return new RenewedLicences($renewed, $expiresAt, $emailed);
        });
    }

    /**
     * Live licences of the company's active tills in active branches.
     *
     * @return Builder<Licence>
     */
    public static function renewable(Company $company): Builder
    {
        return Licence::withoutCompanyScope()
            ->whereBelongsTo($company)
            ->live()
            ->whereHas('register', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))
            ->whereHas('branch', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))
            ->with(['branch', 'register', 'plan', 'company'])
            ->orderBy('created_at');
    }
}
