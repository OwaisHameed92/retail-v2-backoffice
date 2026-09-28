<?php

namespace App\Domain\TillData\Sync\Enums;

/**
 * What happened to an accepted change. Every outcome counts as accepted in the push reply.
 */
enum ChangeOutcome: string
{
    /** Stored (inserted, updated, soft-deleted; for a frozen historic row only its allowed columns). */
    case Applied = 'applied';

    /**
     * Its version was older than the stored row, or equal and not newer by `updatedAt`: nothing changed
     * (contract §7, §19.3).
     */
    case Stale = 'stale';

    /**
     * A hub-owned row whose content is exactly what the portal already holds (an echo of a pulled row, or the same
     * edit arriving from a second shop): nothing changed, nothing is sent down again (contract §19.2).
     */
    case Unchanged = 'unchanged';

    /** This (branch, seq) was already applied by an earlier push: nothing changed. */
    case Duplicate = 'duplicate';

    /** Not applied; a sync_conflicts row records it (hub-owned row with a newer portal edit). */
    case Conflict = 'conflict';
}
