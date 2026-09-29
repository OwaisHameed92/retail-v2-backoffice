<?php

namespace App\Domain\TillData\Sync\Enums;

/**
 * How a person settled a sync_conflicts row (module 2.9B, contract §8, §19.3). By default the portal's row wins:
 * it is already stored and sent to every till.
 */
enum ConflictResolution: string
{
    /** Keep the stored row (the portal's, or the other shop's newer edit) and drop the shop's change. */
    case KeepPortal = 'keepPortal';

    /** Take the shop's version: written as a portal edit and sent to every till. */
    case UseTill = 'useTill';

    /** Nothing to apply (a historic record or a shop's own Company / Branch / till row): noted and closed. */
    case Acknowledged = 'acknowledged';

    public function label(): string
    {
        return match ($this) {
            self::KeepPortal => 'Kept the portal\'s version',
            self::UseTill => 'Used the shop\'s version',
            self::Acknowledged => 'Acknowledged',
        };
    }

    /**
     * @return list<self>
     */
    public static function allowedFor(ConflictKind $kind): array
    {
        return match ($kind) {
            ConflictKind::HubEditNewer, ConflictKind::HubVersionNewer, ConflictKind::BranchEditNewer => [self::KeepPortal, self::UseTill],
            ConflictKind::ImmutableChange, ConflictKind::TenancyDelete => [self::Acknowledged],
        };
    }
}
