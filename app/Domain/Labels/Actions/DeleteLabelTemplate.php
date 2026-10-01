<?php

namespace App\Domain\Labels\Actions;

use App\Domain\Labels\Models\LabelTemplate;
use App\Domain\Shared\Actions\RecordAudit;

/**
 * Deletes a label template (gap #6). Nothing points at a template (each print names its own), so it can go; a shop
 * without templates prints on the built-in default.
 */
final class DeleteLabelTemplate
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(LabelTemplate $template): void
    {
        $before = $template->only(['name', 'stock', 'branch_id', 'is_default']);
        $template->delete();

        $this->audit->handle('label_template.deleted', $template, $before, null, ['name' => $template->name]);
    }
}
