<?php

namespace App\Domain\Leads\Enums;

/**
 * Entries on a lead's timeline. `note` is typed by staff; the rest are written by the lead actions.
 */
enum LeadNoteKind: string
{
    case Note = 'note';
    case Created = 'created';
    case StatusChanged = 'statusChanged';
    case Assigned = 'assigned';
    case FollowUp = 'followUp';
    case Updated = 'updated';
    case Duplicate = 'duplicate';

    public function isSystem(): bool
    {
        return $this !== self::Note;
    }
}
