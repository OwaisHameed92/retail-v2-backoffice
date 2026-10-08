<?php

namespace App\Domain\Licensing\Api;

use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\Support\LicenceLookup;
use App\Domain\Licensing\Api\Support\LocalKeyRegister;
use App\Domain\Licensing\Api\Support\LocalToken;
use App\Domain\Licensing\Api\Support\RedeemErrors;
use App\Domain\Licensing\Api\Support\WrongKeyLimiter;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Signing\Sspos\VerifiedSsposToken;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Sync\Actions\AuthenticateSyncRequest;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Sync\Enums\IdKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use SensitiveParameter;

/**
 * `POST /api/v1/licence/redeem` (module 2.8, contract v1.4.1 §17.6, §17.16, owner decision §17.14 #1):
 *
 * - **A local key report** (a whole `SSPOS1.` local token, no `Authorization`): verified (§17.2 steps 1–4, an expired
 *   key is recorded too) and entered in the local key register: first sighting or the same install code → 200
 *   `recorded`; another install code → 409 `key.used_on_another_install`.
 * - **A token at a linked till** (branch key): the same, but also 422 `licence.expired`, `licence.wrong_shop` (the
 *   token names another business) or `licence.wrong_branch` (another shop). A portal token → 403 `key.not_allowed`
 *   (the till keeps the licence it has; our keys are entered with `licence/activate`).
 * - **A portal licence key at a linked till** (branch key required): our key for this shop → bound to this install
 *   and applied (`applied` + a new token, exactly as `licence/activate`, without sending the sync key again). Unknown
 *   → 404 `key.not_found`; another shop's → 403 `key.not_for_this_branch`; on another PC → 409 `key.already_redeemed`;
 *   withdrawn or past its activate-by date → 410 `key.expired`; suspended, revoked or no free till → 403
 *   `key.not_allowed`. Wrong keys count towards the 5-per-install limit (then 429 `rate.limited`).
 *
 * Never `licence.ids_conflict`: a report is recorded whatever ids it names (§17.6 (b) step 1), and a linked till's
 * ids were checked by its branch key.
 */
class RedeemLicence
{
    public function __construct(
        private readonly LocalToken $tokens,
        private readonly LocalKeyRegister $register,
        private readonly AuthenticateSyncRequest $authenticate,
        private readonly LicenceLookup $lookup,
        private readonly WrongKeyLimiter $wrongKeys,
        private readonly ActivateLicence $activate,
    ) {}

    /**
     * @param  array{companyId: string|null, branchId: string|null, registerId: string|null}  $tillIds  the X-SSPOS-* headers
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function handle(#[SensitiveParameter] string $key, TillRequest $till, #[SensitiveParameter] ?string $bearer, array $tillIds): array
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $key = (string) preg_replace('/\s+/', '', $key);
        $linked = trim((string) $bearer) === '' ? null : $this->authenticate->handle($bearer, $tillIds['companyId'], $tillIds['branchId'], $tillIds['registerId']);

        if (str_starts_with(strtoupper($key), 'SSPOS1.')) {
            return $this->token($this->tokens->verify($key), $till, $linked, $tillIds, $now);
        }

        if ($linked === null) {
            throw new ApiException('auth.invalid_key', 'A licence key can only be entered here on a till linked to the online dashboard. Enter it under Settings > Licence instead.', 401);
        }

        return $this->portalKey($key, $till, $linked);
    }

    /**
     * @param  array{companyId: string|null, branchId: string|null, registerId: string|null}  $tillIds
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function token(VerifiedSsposToken $token, TillRequest $till, ?SyncCaller $linked, array $tillIds, CarbonImmutable $now): array
    {
        if ($token->source() !== 'local') {
            throw RedeemErrors::keyNotAllowed('This is a portal licence, not a dealer key. Enter the licence key from your e-mail under Settings > Licence.');
        }

        if ($linked !== null) {
            $this->checkForShop($token, $linked, $now);
        }

        [$companyId, $branchId] = $linked !== null ? [$linked->company->id, $linked->branch->id] : LocalKeyRegister::resolve($tillIds['companyId'], $tillIds['branchId']);
        $record = $this->register->record($token, $till, $companyId, $branchId, $linked !== null ? 'redeem' : 'report');

        return [
            'result' => 'recorded',
            'status' => LocalToken::status($token, $now),
            'licenceToken' => null,
            'licence' => LocalToken::summary($token, $record->install_code),
            'portalTimeUtc' => ApiDate::format($now),
            'nextCheckAfterSeconds' => 86400,
            'messages' => [],
        ];
    }

    /**
     * §17.6 (a): a token typed at a linked till must be live and for this business and shop (ids blank = any).
     *
     * @throws ApiException licence.expired, licence.wrong_shop, licence.wrong_branch
     */
    private function checkForShop(VerifiedSsposToken $token, SyncCaller $linked, CarbonImmutable $now): void
    {
        if ($now->greaterThan($token->date('expiresAt') ?? $now)) {
            throw RedeemErrors::expired();
        }

        $company = LocalToken::text($token->get('companyId'));
        $branch = LocalToken::text($token->get('branchId'));

        if ($company !== null && $company !== $linked->tillCompanyId && $linked->ids->toPortal(IdKind::Company, $company) !== $linked->company->id) {
            throw RedeemErrors::wrongShop();
        }

        if ($branch !== null && $branch !== $linked->tillBranchId && $linked->ids->toPortal(IdKind::Branch, $branch) !== $linked->branch->id) {
            throw RedeemErrors::wrongBranch();
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function portalKey(#[SensitiveParameter] string $key, TillRequest $till, SyncCaller $linked): array
    {
        try {
            $this->wrongKeys->ensureAllowed($till);
        } catch (ApiException $e) {
            throw LicenceApiErrors::rateLimited($e->retryAfterSeconds ?? 900);
        }

        try {
            $licence = $this->lookup->byKey(LicenceKey::tryParse($key) ?? throw LicenceApiErrors::keyNotFound(), $till);
        } catch (ApiException $e) {
            $this->wrongKeys->hit($till);

            throw $e;
        }

        if ($licence->company_id !== $linked->company->id || $licence->branch_id !== $linked->branch->id) {
            throw RedeemErrors::keyNotForThisBranch();
        }

        if ($licence->isBound() && $licence->device_id !== $till->installId) {
            throw RedeemErrors::keyAlreadyRedeemed($licence);
        }

        try {
            $reply = $this->activate->handle($key, $till, deliverSyncKey: false);
        } catch (ApiException $e) {
            throw match ($e->errorCode) {
                'key.already_used' => RedeemErrors::keyAlreadyRedeemed($licence->refresh()),
                'licence.not_active', 'licence.seat_limit', 'licence.ids_conflict' => RedeemErrors::keyNotAllowed($e->getMessage()),
                'activation.too_many_attempts' => LicenceApiErrors::rateLimited($e->retryAfterSeconds ?? 900),
                default => $e,
            };
        }

        // The redeem reply carries no sync link: neither the key nor the shop ids that go with it (redeem-reply.applied.json).
        // §17.18 names activate and validate for the top-level `country` (PK); the token inside carries it anyway.
        return ['result' => 'applied', ...Arr::except($reply, ['apiKey', 'hubUrl', 'companyId', 'branchId', 'country'])];
    }
}
