<?php

namespace App\Domain\Notifications\Enums;

use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\CompanyRole;

/**
 * What a business owner can be told about by email and in the bell (module 7.8). camelCase values.
 *
 * Urgent types (till offline, sync failing) come from the Till health alerts (module 2.7, `licence_alerts`) and may be
 * emailed straight away; the others only go in the daily digest. The morning summary (module 6.3) is a part of the
 * same 07:00 email: on ("daily digest") or off. Each type needs the role ability of the screen it
 * links to; staff get nothing unless they turn it on themselves.
 */
enum AlertType: string
{
    case TillOffline = 'tillOffline';
    case SyncFailing = 'syncFailing';
    case LowStock = 'lowStock';
    case CashVariance = 'cashVariance';
    case Compliance = 'compliance';
    case SyncConflicts = 'syncConflicts';
    case MorningSummary = 'morningSummary';

    public function label(): string
    {
        return match ($this) {
            self::TillOffline => 'Till offline',
            self::SyncFailing => 'Sync failing or stalled',
            self::LowStock => 'Low and negative stock',
            self::CashVariance => 'Cash variances',
            self::Compliance => 'Compliance expiries and recalls',
            self::SyncConflicts => 'Sync conflicts waiting',
            self::MorningSummary => 'Morning summary',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TillOffline => 'A till has not been in touch for a few hours while the shop is open.',
            self::SyncFailing => 'A shop\'s sales and stock are not reaching the portal.',
            self::LowStock => 'Products at or below their low-stock point, out of stock or below zero.',
            self::CashVariance => 'Yesterday\'s till, safe or banking differences over your alert amount.',
            self::Compliance => 'Staff training and licences expired or expiring within 14 days, and open product recalls.',
            self::SyncConflicts => 'Changes from a shop that the portal kept out and that need a decision.',
            self::MorningSummary => 'Yesterday\'s sales against last week and last year, top movers, unusual refunds or discounts and fast sellers running low, with a short written summary.',
        };
    }

    /** May be emailed straight away (else only off or digest). */
    public function urgent(): bool
    {
        return $this === self::TillOffline || $this === self::SyncFailing;
    }

    /** The role ability needed to receive it (the screen it links to). */
    public function ability(): Ability
    {
        return match ($this) {
            self::TillOffline, self::SyncFailing => Ability::ShopsView,
            self::LowStock => Ability::StockView,
            self::CashVariance => Ability::CashView,
            self::Compliance => Ability::ComplianceView,
            self::SyncConflicts => Ability::SyncManage,
            self::MorningSummary => Ability::ReportsView,
        };
    }

    /** Before the user chooses: owners and managers get urgent ones straight away and the rest daily; staff nothing. */
    public function defaultFor(CompanyRole $role): AlertDelivery
    {
        if (! $role->can($this->ability())) {
            return AlertDelivery::Off;
        }

        return match ($role) {
            CompanyRole::Owner, CompanyRole::Manager => $this->urgent() ? AlertDelivery::Immediate : AlertDelivery::Digest,
            CompanyRole::Accountant => $this === self::CashVariance ? AlertDelivery::Digest : AlertDelivery::Off,
            CompanyRole::Staff => AlertDelivery::Off,
        };
    }

    /**
     * The deliveries the user may pick.
     *
     * @return list<AlertDelivery>
     */
    public function deliveries(): array
    {
        return $this->urgent() ? AlertDelivery::cases() : [AlertDelivery::Off, AlertDelivery::Digest];
    }

    /** The Till health alert (module 2.7) behind an urgent type. */
    public static function fromLicenceAlert(LicenceAlertType $type): ?self
    {
        return match ($type) {
            LicenceAlertType::TillOffline => self::TillOffline,
            LicenceAlertType::SyncFailing, LicenceAlertType::SyncStalled => self::SyncFailing,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function licenceAlertValues(): array
    {
        return [LicenceAlertType::TillOffline->value, LicenceAlertType::SyncFailing->value, LicenceAlertType::SyncStalled->value];
    }

    /**
     * Types this role may receive.
     *
     * @return list<self>
     */
    public static function forRole(CompanyRole $role): array
    {
        return array_values(array_filter(self::cases(), fn (self $type) => $role->can($type->ability())));
    }
}
