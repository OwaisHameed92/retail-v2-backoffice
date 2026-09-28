<?php

namespace App\Domain\TillData\Sync\Enums;

/**
 * Why a sync_conflicts row was recorded.
 */
enum ConflictKind: string
{
    /** A till pushed a hub-owned row the portal edited after the till's change. The portal row was kept. */
    case HubEditNewer = 'hubEditNewer';

    /**
     * A till pushed a hub-owned row with a `baseVersion` below the portal's current version for it (the portal
     * changed the row meanwhile, §19.3). The portal row was kept.
     */
    case HubVersionNewer = 'hubVersionNewer';

    /** A till changed columns of a historic row (completed sale, journal, audit…). Only allowed columns applied. */
    case ImmutableChange = 'immutableChange';

    /** A till soft-deleted its Company, Branch or Register row. Portal-owned: not deleted. */
    case TenancyDelete = 'tenancyDelete';
}
