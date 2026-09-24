<?php

namespace App\Domain\TillData\Sync\Enums;

/**
 * What happened to an accepted change. Every outcome counts as accepted in the push reply.
 */
enum ChangeOutcome: string
{
    /** Stored (inserted, updated, soft-deleted; for a frozen historic row only its allowed columns). */
    case Applied = 'applied';

    /** Its version was not newer than the stored row: nothing changed (contract section 7). */
    case Stale = 'stale';

    /** This (branch, seq) was already applied by an earlier push: nothing changed. */
    case Duplicate = 'duplicate';

    /** Not applied; a sync_conflicts row records it (hub-owned row with a newer portal edit). */
    case Conflict = 'conflict';
}
