<?php

namespace App\Domain\Leads\Data;

use App\Domain\Shared\Models\AuditLog;

/**
 * Readable text for `lead.*` audit entries. After a lead is converted its entries carry the company id, so the
 * tenant page's Activity (TenantActivity) shows them too.
 */
final class LeadActivity
{
    public static function describe(AuditLog $entry): string
    {
        $meta = $entry->meta ?? [];

        return match ($entry->action) {
            'lead.converted' => 'Approved the trial request: created from a lead with '
                .self::count((int) ($meta['shops'] ?? 1), 'shop').' and '.self::count((int) ($meta['tills'] ?? 1), 'till'),
            'lead.created' => 'Added the lead',
            'lead.updated' => 'Updated the lead’s details',
            'lead.note_added' => 'Added a note to the lead',
            'lead.assigned' => 'Changed who looks after the lead',
            'lead.follow_up_set' => 'Changed the lead’s follow-up',
            'lead.contacted' => 'Marked the lead as contacted',
            'lead.rejected' => 'Rejected the lead: '.($meta['reason'] ?? ''),
            'lead.reopened' => 'Reopened the lead',
            'lead.archived' => 'Archived the lead',
            'lead.restored' => 'Restored the lead',
            default => $entry->action,
        };
    }

    private static function count(int $count, string $singular): string
    {
        return $count.' '.($count === 1 ? $singular : $singular.'s');
    }
}
