<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\LeadTimeline;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives a lead to a member of staff who works leads (active, with `leads.manage`), or unassigns it (null).
 */
class AssignLead
{
    public function __construct(
        private readonly LeadTimeline $timeline,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, ?Admin $admin): Lead
    {
        if ($admin !== null) {
            self::ensureAssignable($admin);
        }

        if ($lead->assigned_admin_id === $admin?->id) {
            return $lead;
        }

        return DB::transaction(function () use ($lead, $admin) {
            $before = $lead->assigned_admin_id;
            $previous = $lead->assignedAdmin;

            $lead->assigned_admin_id = $admin?->id;
            $lead->save();
            $lead->setRelation('assignedAdmin', $admin);

            $this->timeline->record(
                $lead,
                LeadNoteKind::Assigned,
                $admin === null ? 'Unassigned'.($previous !== null ? " (was {$previous->name})" : '') : "Assigned to {$admin->name}",
                ['admin_id' => $admin?->id, 'previous_admin_id' => $before],
            );

            $this->audit->handle('lead.assigned', $lead, ['assigned_admin_id' => $before], ['assigned_admin_id' => $admin?->id]);

            return $lead;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function ensureAssignable(Admin $admin): void
    {
        if (! $admin->hasAbility(AdminRole::LEADS_MANAGE)) {
            throw ValidationException::withMessages(['admin_id' => "{$admin->name} cannot work leads. Choose someone in sales or the owner."]);
        }
    }
}
