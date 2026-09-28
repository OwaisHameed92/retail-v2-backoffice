<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\Sspos\LicenceClaims;
use App\Domain\Licensing\Signing\Sspos\SsposCodec;
use App\Domain\Licensing\Signing\Sspos\SsposTokenSigner;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use App\Domain\Tenancy\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The SSPOS1 token of one till's licence (contract v1.3.1 §17.15.1 step 5), and whether validate must send a
 * new one (§17.5 step 3).
 *
 * - `companyId`/`branchId`/names/`company` block: the portal's company and branch of the licence (the same in
 *   every till key of the branch), never the till's `existingIds`.
 * - `installCode`: the one the till sent. `maxRegisters`: the branch's active tills. `features`: the licence's
 *   plan features mapped by config('licence.till_features'); `limits.branches` only with `multi_branch`.
 * - `validFrom` = first activation; `expiresAt` = the last day the till may trade, i.e. the end date plus our
 *   grace days (DECISIONS "Licence API v1.3.1"); `kind` trial until the licence is paid.
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
        $features = self::features($licence->features);
        $expiresAt = self::expiresAt($state, $now);
        $validFrom = ($licence->activated_at ?? $now)->utc()->startOfSecond();

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
            maxRegisters: self::maxRegisters($licence->branch_id),
            issuedAt: $now->utc()->startOfSecond(),
            validFrom: $validFrom,
            expiresAt: $expiresAt,
            installCode: $licence->install_code,
            features: $features,
            limits: in_array('multi_branch', $features, true) ? ['branches' => self::branches($licence->company_id)] : [],
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
     * Our plan features as the till's names (config('licence.till_features')), in enum order; unmapped ones
     * are left out.
     *
     * @param  Collection<int, Feature>|iterable<Feature>  $features
     * @return list<string>
     */
    public static function features(iterable $features): array
    {
        /** @var array<string, string> $map */
        $map = (array) config('licence.till_features', []);
        $names = [];

        foreach (Feature::normalise($features) as $feature) {
            $name = $map[$feature->value] ?? null;

            if (is_string($name) && preg_match(LicenceClaims::FEATURE, $name) === 1) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /** Tills allowed in the branch: its active tills (at least 1). */
    public static function maxRegisters(string $branchId): int
    {
        $count = Register::withoutGlobalScope(CompanyScope::class)->where('branch_id', $branchId)->where('is_active', true)->count();

        return max(1, min(999, $count));
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

    private static function branches(string $companyId): int
    {
        return max(1, Branch::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('is_active', true)->count());
    }

    /**
     * The shop's details from the portal's company and branch (§17.16). Fields we do not hold yet
     * (businessType, town, postcode, receiptFooter) are left out.
     *
     * @return array<string, string>
     */
    private static function companyBlock(Licence $licence): array
    {
        $company = $licence->company;
        $branch = $licence->branch;
        $values = [
            'address' => $branch?->address ?: $company?->address,
            'phone' => $branch?->phone ?: $company?->phone,
            'email' => $company?->email,
            'vatNumber' => $branch?->vat_number ?: $company?->vat_number,
            'ownerName' => $company?->contact_name,
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
}
