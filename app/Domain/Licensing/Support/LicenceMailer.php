<?php

namespace App\Domain\Licensing\Support;

use App\Domain\Licensing\Data\IssuedLicence;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Data\LicenceKeyData;
use App\Domain\Mail\Data\LicenceRenewedData;
use App\Domain\Mail\Data\RenewedTillData;
use App\Domain\Mail\Data\TillKeyData;
use App\Domain\Mail\Data\WelcomeTenantData;
use App\Domain\Mail\Mailables\LicenceKeyMail;
use App\Domain\Mail\Mailables\LicenceRenewedMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Builds and queues the licence emails of module 1.7 (branded, encrypted on the queue, sent after commit).
 * Keys go into the mail data only; the email log never sees them (mailables keep them out of logMeta).
 */
final class LicenceMailer
{
    /**
     * The welcome email with every new till's key, to the owner who was just set up.
     *
     * @param  list<IssuedLicence>  $issued
     */
    public function welcome(Company $company, User $owner, array $issued): void
    {
        $trialDays = $issued === [] ? null : ($issued[0]->licence->plan->trial_days ?? null);

        Mail::to($owner->email)->queue(new WelcomeTenantMail(new WelcomeTenantData(
            businessName: $company->name,
            ownerName: $owner->name,
            ownerEmail: $owner->email,
            loginUrl: config('sspos.portal_url').'/login',
            tills: $this->tillKeys($issued),
            trialDays: $company->status === CompanyStatus::Trial && $trialDays > 0 ? $trialDays : null,
            companyId: $company->id,
        )));
    }

    /**
     * New or replaced keys to every active owner. Returns how many owners were emailed.
     *
     * @param  list<IssuedLicence>  $issued
     */
    public function keys(Company $company, array $issued, bool $replacesOldKey): int
    {
        $owners = $this->owners($company);

        foreach ($owners as $owner) {
            Mail::to($owner->email)->queue(new LicenceKeyMail(new LicenceKeyData(
                businessName: $company->name,
                ownerName: $owner->name,
                tills: $this->tillKeys($issued),
                replacesOldKey: $replacesOldKey,
                companyId: $company->id,
            )));
        }

        return $owners->count();
    }

    /**
     * "Your licences are renewed" to every active owner. Returns how many owners were emailed.
     *
     * @param  list<Licence>  $licences
     */
    public function renewed(Company $company, array $licences, CarbonImmutable $expiresAt): int
    {
        $owners = $this->owners($company);
        $tills = array_map(fn (Licence $licence) => new RenewedTillData(
            $licence->branch->name ?? 'Branch',
            $licence->register->name ?? 'Till',
            $licence->expires_at ?? $expiresAt,
        ), $licences);

        foreach ($owners as $owner) {
            Mail::to($owner->email)->queue(new LicenceRenewedMail(new LicenceRenewedData(
                businessName: $company->name,
                ownerName: $owner->name,
                tills: $tills,
                newExpiry: $expiresAt,
                companyId: $company->id,
            )));
        }

        return $owners->count();
    }

    /**
     * @return Collection<int, User>
     */
    public function owners(Company $company): Collection
    {
        return $company->owners()->orderBy('users.id')->get();
    }

    /**
     * @param  list<IssuedLicence>  $issued
     * @return list<TillKeyData>
     */
    private function tillKeys(array $issued): array
    {
        return array_map(fn (IssuedLicence $item) => new TillKeyData(
            $item->licence->branch->name ?? 'Branch',
            $item->licence->register->name ?? 'Till',
            $item->plainKey(),
        ), $issued);
    }
}
