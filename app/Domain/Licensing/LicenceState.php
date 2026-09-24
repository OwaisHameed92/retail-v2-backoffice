<?php

namespace App\Domain\Licensing;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;

/**
 * The effective status of a licence: what the till would be told at `$now`. Combines, in this order:
 *
 * 1. revoked (stored) → revoked
 * 2. suspended (stored) → suspended, with the admin's reason
 * 3. company suspended, cancelled or deleted → suspended
 * 4. branch inactive or deleted → suspended
 * 5. till (register) inactive or deleted → suspended
 * 6. otherwise the dates: issued (never activated), trial, active, grace, expired (LicenceTerms::naturalStatus)
 *
 * The admin list filters with the same rules in SQL (EffectiveStatusQuery); a test keeps the two in step.
 */
final readonly class LicenceState
{
    public const REASON_REVOKED = 'licence.revoked';

    public const REASON_SUSPENDED = 'licence.suspended';

    public const REASON_COMPANY_SUSPENDED = 'company.suspended';

    public const REASON_COMPANY_CANCELLED = 'company.cancelled';

    public const REASON_BRANCH_INACTIVE = 'branch.inactive';

    public const REASON_TILL_INACTIVE = 'register.inactive';

    public const REASON_EXPIRED = 'licence.expired';

    public function __construct(
        public LicenceStatus $status,
        /** Why the till is locked (one of the REASON_* codes), null when it can trade or is just issued. */
        public ?string $reasonCode,
        /** en-GB sentence for staff and the till's message, e.g. "The business is suspended." */
        public ?string $reason,
        /** Trial end or paid expiry. */
        public ?CarbonImmutable $endsAt,
        /** When the grace after endsAt runs out. */
        public ?CarbonImmutable $graceEndsAt,
        public bool $isTrial,
    ) {}

    public static function for(Licence $licence, CarbonImmutable $now): self
    {
        $endsAt = LicenceTerms::endsAt($licence);
        $graceEndsAt = LicenceTerms::graceEndsAt($licence);
        $isTrial = $licence->activated_at !== null && ! LicenceTerms::isPaid($licence);
        $make = fn (LicenceStatus $status, ?string $code = null, ?string $reason = null) => new self($status, $code, $reason, $endsAt, $graceEndsAt, $isTrial);

        if ($licence->status === LicenceStatus::Revoked) {
            return $make(LicenceStatus::Revoked, self::REASON_REVOKED, self::withReason('This licence has been revoked.', $licence->revoked_reason));
        }

        if ($licence->status === LicenceStatus::Suspended) {
            return $make(LicenceStatus::Suspended, self::REASON_SUSPENDED, self::withReason('This licence is suspended.', $licence->suspended_reason));
        }

        $company = $licence->company;
        if ($company === null || $company->trashed() || $company->status === CompanyStatus::Cancelled) {
            return $make(LicenceStatus::Suspended, self::REASON_COMPANY_CANCELLED, 'The business account is closed.');
        }
        if ($company->status === CompanyStatus::Suspended) {
            return $make(LicenceStatus::Suspended, self::REASON_COMPANY_SUSPENDED, 'The business account is suspended.');
        }

        $branch = $licence->branch;
        if ($branch === null || $branch->trashed() || ! $branch->is_active) {
            return $make(LicenceStatus::Suspended, self::REASON_BRANCH_INACTIVE, 'The branch is deactivated.');
        }

        $register = $licence->register;
        if ($register === null || $register->trashed() || ! $register->is_active) {
            return $make(LicenceStatus::Suspended, self::REASON_TILL_INACTIVE, 'The till is deactivated.');
        }

        $status = LicenceTerms::naturalStatus($licence, $now);

        return $status === LicenceStatus::Expired
            ? $make($status, self::REASON_EXPIRED, $isTrial ? 'The free trial has ended.' : 'The licence has expired.')
            : $make($status);
    }

    public function canTrade(): bool
    {
        return $this->status->canTrade();
    }

    private static function withReason(string $sentence, ?string $reason): string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? $sentence : $sentence.' '.rtrim($reason, '.').'.';
    }
}
