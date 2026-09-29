<?php

namespace App\Domain\Sync\Support;

use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\Support\LicenceBinder;
use App\Domain\Licensing\Api\Support\LicenceToken;
use App\Domain\Licensing\Api\Support\TillAudit;
use App\Domain\Licensing\Api\Support\TillStatus;
use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Signing\Sspos\LicenceClaims;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use App\Domain\Licensing\Signing\Sspos\VerifiedSsposToken;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;

/**
 * The portal licence a migrating main till gets in the `cloud/migrate` reply (contract v1.4.1 §17.8 steps 2 and 4):
 *
 * - the shop's live licence already bound to this PC (a shop on a portal licence, or a retry), else
 * - a free (unbound) licence of the shop, the main till's first, bound to this PC exactly as `licence/activate`
 *   would (first activation starts its term), else 403 licence.seat_limit. Suspended or revoked → 403
 *   licence.not_active.
 * - Days carry over (owner rule): a local key's end date later than ours moves our end date to it (a full key the
 *   paid end, a trial key the trial end), audited as `licence.carried_over`. `carriedOverDays` = the days left on
 *   the local key.
 */
final class MigrationLicence
{
    public function __construct(
        private readonly LicenceBinder $binder,
        private readonly LicenceToken $tokens,
        private readonly TillAudit $audit,
    ) {}

    /**
     * Call inside the migration's transaction.
     *
     * @return array{licence: Licence, claims: LicenceClaims, status: string, token: string, carriedOverDays: int}
     *
     * @throws ApiException licence.seat_limit, licence.not_active
     */
    public function forInstall(Branch $branch, TillRequest $till, ?Licence $chosen, ?VerifiedSsposToken $local, CarbonImmutable $now): array
    {
        $licence = $chosen ?? $this->pick($branch, $till);
        $licence = Licence::withoutCompanyScope()->lockForUpdate()->findOrFail($licence->id);
        $state = LicenceState::for($licence, $now);

        if (in_array($state->status, [LicenceStatus::Revoked, LicenceStatus::Suspended], true)) {
            throw LicenceApiErrors::notActive($state->status->value, $state->reason);
        }

        $licence->isBound() ? $this->binder->reinstall($licence, $till, $now) : $this->binder->bind($licence, $till, $now);
        $carried = $local === null ? 0 : $this->carryOver($licence, $local, $till, $now);

        $state = LicenceState::for($licence, $now);
        $claims = $this->tokens->claims($licence, $state, $now);
        $token = $this->tokens->issue($licence, $claims);
        $licence->save();

        return ['licence' => $licence, 'claims' => $claims, 'status' => TillStatus::of($state, $claims->expiresAt, $now), 'token' => $token, 'carriedOverDays' => $carried];
    }

    /** @throws ApiException licence.seat_limit */
    private function pick(Branch $branch, TillRequest $till): Licence
    {
        $live = Licence::withoutCompanyScope()->live()->where('branch_id', $branch->id)->get();
        $mine = $live->firstWhere('device_id', $till->installId);

        if ($mine !== null) {
            return $mine;
        }

        $main = Register::withoutCompanyScope()->where('branch_id', $branch->id)->where('is_main_till', true)->value('id');
        $free = $live->whereNull('device_id')->sortBy(fn (Licence $l) => [$l->register_id === $main ? 0 : 1, (string) $l->created_at, $l->id])->first();

        return $free ?? throw LicenceApiErrors::seatLimit(LicenceToken::maxRegisters($branch), LicenceToken::registersInUse($branch->id));
    }

    private function carryOver(Licence $licence, VerifiedSsposToken $local, TillRequest $till, CarbonImmutable $now): int
    {
        $localEnd = $local->date('expiresAt');

        if ($localEnd === null || ! $localEnd->greaterThan($now)) {
            return 0;
        }

        $full = $local->kind() !== TokenKind::Trial;
        $current = $full ? $licence->expires_at : LicenceTerms::endsAt($licence);

        if ($current === null || $current->lessThan($localEnd)) {
            $before = ['expires_at' => $licence->expires_at?->toIso8601String(), 'trial_ends_at' => $licence->trial_ends_at?->toIso8601String(), 'status' => $licence->status->value];

            if ($full) {
                $licence->expires_at = $localEnd;
                $licence->grace_days = $licence->plan->grace_days ?? $licence->grace_days;
            } else {
                $licence->trial_ends_at = $localEnd;
            }

            $licence->status = LicenceTerms::storedStatusAfterDateChange($licence, $now);
            $licence->save();

            $this->audit->record('licence.carried_over', $licence, $before, [
                'expires_at' => $licence->expires_at?->toIso8601String(),
                'trial_ends_at' => $licence->trial_ends_at?->toIso8601String(),
                'status' => $licence->status->value,
            ], $till, ['local_licence_id' => $local->licenceId(), 'local_kind' => $full ? 'full' : 'trial']);
        }

        return (int) floor($now->diffInSeconds($localEnd) / 86400);
    }
}
