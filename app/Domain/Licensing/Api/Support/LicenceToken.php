<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\Sspos\LicenceClaims;
use App\Domain\Licensing\Signing\Sspos\SsposCodec;
use App\Domain\Licensing\Signing\Sspos\SsposTokenSigner;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use App\Domain\Licensing\Support\BranchLicenceTerm;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\TenantLimits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The SSPOS1 token of one till's licence (contract v1.3.1 §17.15.1 step 5), and whether validate must send a
 * new one (§17.5 step 3).
 *
 * - `companyId`/`branchId`/names/`company` block: the portal's company and branch of the licence (the same in
 *   every till key of the branch), never the till's `existingIds`.
 * - `installCode`: the one the till sent. `maxRegisters`: the branch's tills allowed (module 1.11 licence
 *   settings). `features`: the licence's features (already the till's names), with `multi_branch`
 *   exactly when the company has multi-branch on; then `limits.branches` = its branches allowed.
 * - `validFrom` = the branch's start date, else first activation; `expiresAt` = the last day the till may trade,
 *   i.e. the end date plus our grace days (DECISIONS "Licence API v1.3.1"); `kind` trial until paid (full).
 * - `company` block: business type, owner's name from the company; address, town, postcode from the branch when
 *   it has an address, else the company; phone, VAT number, receipt footer from the branch, else the company.
 */
final class LicenceToken
{
    public function __construct(
        private readonly SsposTokenSigner $signer,
        private readonly KeyStore $keys,
    ) {}

    public function claims(Licence $licence, LicenceState $state, CarbonImmutable $now): LicenceClaims
    {
        $licence->loadMissing(['company', 'branch']);
        $company = $licence->company;
        $branch = $licence->branch;
        $multiBranch = (bool) $company?->multi_branch;
        $features = self::features($licence->features, $multiBranch);
        $expiresAt = self::expiresAt($state, $now);
        $validFrom = (BranchLicenceTerm::start($licence, $branch) ?? $now)->utc()->startOfSecond();

        if (! $expiresAt->greaterThan($validFrom)) {
            $validFrom = $expiresAt->subDay();
        }

        return new LicenceClaims(
            licenceId: $licence->id,
            kind: $state->isTrial || $licence->activated_at === null ? TokenKind::Trial : TokenKind::Full,
            companyId: $licence->company_id,
            branchId: $licence->branch_id,
            businessName: mb_substr((string) $company?->name, 0, 100),
            branchName: mb_substr((string) $branch?->name, 0, 100),
            maxRegisters: self::maxRegisters($branch),
            issuedAt: $now->utc()->startOfSecond(),
            validFrom: $validFrom,
            expiresAt: $expiresAt,
            installCode: $licence->install_code,
            features: $features,
            limits: $multiBranch ? ['branches' => TenantLimits::branchesAllowed($company)] : [],
            company: self::companyBlock($licence),
        );
    }

    /** When the till must stop trading: grace end, else end date; a licence with no dates ends now. */
    public static function expiresAt(LicenceState $state, CarbonImmutable $now): CarbonImmutable
    {
        return ($state->graceEndsAt ?? $state->endsAt ?? $now)->utc()->startOfSecond();
    }

    /**
     * Signs the token and remembers its hash, kid and claim fingerprint on the licence (the caller saves).
     */
    public function issue(Licence $licence, LicenceClaims $claims): string
    {
        $token = $this->signer->sign($claims);

        $licence->token_sha256 = hash('sha256', $token);
        $licence->token_kid = (string) SsposCodec::parse(SsposCodec::TOKEN_PREFIX, $token)['payload']['kid'];
        $licence->token_fingerprint = self::fingerprint($claims);

        return $token;
    }

    /**
     * §17.5 step 3: a new token when the till holds another token than our last one, our last one's kid is not
     * one the till trusts (or is no longer our signing key), or anything in the claims changed.
     *
     * @param  list<string>  $trustedKids
     */
    public function needsNew(Licence $licence, LicenceClaims $claims, string $tokenSha256, array $trustedKids): bool
    {
        return $licence->token_sha256 === null
            || ! hash_equals($licence->token_sha256, strtolower($tokenSha256))
            || ! in_array($licence->token_kid, $trustedKids, true)
            || $licence->token_kid !== $this->keys->active()->kid
            || $licence->token_fingerprint !== self::fingerprint($claims);
    }

    /** SHA-256 of the claims without the parts that change on every signature. */
    public static function fingerprint(LicenceClaims $claims): string
    {
        $payload = $claims->toPayload('-', null);
        unset($payload['kid'], $payload['issuedAt']);

        return hash('sha256', (string) json_encode($payload, SsposCodec::JSON_FLAGS));
    }

    /**
     * The licence's features as the till's names (our Feature values are exactly the till's 11 names, module 2.1),
     * in enum order. `$multiBranch` true/false forces `multi_branch` on/off (the company's setting).
     *
     * @param  Collection<int, Feature>|iterable<Feature>  $features
     * @return list<string>
     */
    public static function features(iterable $features, ?bool $multiBranch = null): array
    {
        $features = Feature::normalise($features);

        if ($multiBranch !== null) {
            $features = array_filter($features, fn (Feature $feature) => $feature !== Feature::MultiBranch);
            $features = Feature::normalise($multiBranch ? [...$features, Feature::MultiBranch] : $features);
        }

        return array_values(array_filter(
            array_map(fn (Feature $feature) => $feature->value, $features),
            fn (string $name) => preg_match(LicenceClaims::FEATURE, $name) === 1,
        ));
    }

    /** Tills allowed in the branch: its licence setting (module 1.11), 1 to 999. */
    public static function maxRegisters(?Branch $branch): int
    {
        return max(1, min(999, (int) $branch?->max_registers));
    }

    /** Tills of the branch whose live portal key is bound to an install (§17.16 "counts the tills"). */
    public static function registersInUse(string $branchId, ?string $exceptLicenceId = null): int
    {
        return Licence::withoutCompanyScope()->live()
            ->where('branch_id', $branchId)
            ->whereNotNull('device_id')
            ->when($exceptLicenceId !== null, fn ($query) => $query->whereKeyNot($exceptLicenceId))
            ->count();
    }

    /**
     * The shop's details from the portal's company and branch (§17.2 `company`, module 1.11).
     *
     * @return array<string, string>
     */
    private static function companyBlock(Licence $licence): array
    {
        $company = $licence->company;
        $branch = $licence->branch;
        $place = trim((string) $branch?->address) !== '' ? $branch : $company;
        $values = [
            'businessType' => $company?->business_type?->value,
            'address' => $place?->address,
            'town' => $place?->town,
            'postcode' => $place?->postcode,
            'phone' => $branch?->phone ?: $company?->phone,
            'email' => $company?->email,
            'vatNumber' => $branch?->vat_number ?: $company?->vat_number,
            'ownerName' => $company === null ? null : self::ownerName($company),
            'receiptFooter' => $branch?->receipt_footer ?: $company?->receipt_footer,
        ];
        $block = [];

        foreach ($values as $field => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $block[$field] = mb_substr($value, 0, LicenceClaims::COMPANY_FIELDS[$field]);
            }
        }

        return $block;
    }

    /** The owner's name on the form, else the first active owner login, else the main contact. */
    private static function ownerName(Company $company): ?string
    {
        if (trim((string) $company->owner_name) !== '') {
            return $company->owner_name;
        }

        return $company->owners()->orderBy('users.id')->value('users.name') ?? $company->contact_name;
    }
}
