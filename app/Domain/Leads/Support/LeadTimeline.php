<?php

namespace App\Domain\Leads\Support;

use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadNote;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * Writes entries on a lead's timeline. The author is the signed-in admin unless one is passed; the public trial
 * form (no admin) writes system entries with no author.
 */
final class LeadTimeline
{
    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * @param  array<string, mixed>  $meta  Non-secret facts for the UI (statuses, dates, ids).
     */
    public function record(Lead $lead, LeadNoteKind $kind, string $body, array $meta = [], ?Admin $author = null): LeadNote
    {
        $author ??= $this->currentAdmin();

        return LeadNote::query()->create([
            'lead_id' => $lead->id,
            'admin_id' => $author?->id,
            'kind' => $kind,
            'body' => $body,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    public function currentAdmin(): ?Admin
    {
        $admin = $this->auth->guard('admin')->user();

        return $admin instanceof Admin ? $admin : null;
    }
}
