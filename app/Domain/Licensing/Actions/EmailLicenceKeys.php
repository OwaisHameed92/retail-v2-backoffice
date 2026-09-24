<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\IssuedLicence;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceMailer;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * "Email this key to the owner" from the admin's "Licence key created" dialog. The portal cannot recover a key
 * (only its hash is stored), so the dialog sends the keys it was shown; each must match its licence's hash, the
 * licences must be live and belong to one company. Emails every active owner (LicenceKeyMail). Audited without
 * the keys.
 */
class EmailLicenceKeys
{
    public function __construct(
        private readonly LicenceMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  array<string, string>  $keys  licence id => plain key as shown
     * @return int Owners emailed.
     *
     * @throws ValidationException
     */
    public function handle(#[SensitiveParameter] array $keys): int
    {
        if ($keys === []) {
            throw ValidationException::withMessages(['licences' => 'Choose at least one key to email.']);
        }

        $licences = Licence::withoutCompanyScope()->with(['company', 'branch', 'register'])->whereKey(array_keys($keys))->get()->keyBy('id');

        $issued = [];
        foreach ($keys as $id => $plain) {
            $licence = $licences->get($id);
            $key = LicenceKey::tryParse($plain);

            if ($licence === null || $key === null || ! $key->matches($licence->key_hash)) {
                throw ValidationException::withMessages(['licences' => 'A key does not match its licence any more. It may have been replaced.']);
            }

            if ($licence->isRevoked()) {
                throw ValidationException::withMessages(['licences' => 'A licence was revoked, so its key cannot be sent.']);
            }

            $issued[] = new IssuedLicence($licence, $key);
        }

        $companyIds = array_unique(array_map(fn (IssuedLicence $item) => $item->licence->company_id, $issued));
        if (count($companyIds) !== 1) {
            throw ValidationException::withMessages(['licences' => 'Keys of different businesses cannot go in one email.']);
        }

        /** @var Company $company */
        $company = $issued[0]->licence->company;

        if ($company->isCancelled() || $company->trashed()) {
            throw ValidationException::withMessages(['licences' => "{$company->name} is cancelled, so keys cannot be sent."]);
        }

        if ($this->mailer->owners($company)->isEmpty()) {
            throw ValidationException::withMessages(['licences' => "{$company->name} has no active owner to email. Add an owner first."]);
        }

        $emailed = $this->mailer->keys($company, $issued, replacesOldKey: false);

        foreach ($issued as $item) {
            $this->audit->handle('licence.key_emailed', $item->licence, null, null, ['owners' => $emailed, 'key_last4' => $item->licence->key_last4]);
        }

        return $emailed;
    }
}
