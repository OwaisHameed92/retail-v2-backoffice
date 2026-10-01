<?php

namespace App\Domain\Labels\Actions;

use App\Domain\Labels\Models\LabelTemplate;
use App\Domain\Labels\Support\LabelStocks;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a label template (gap #6): name, stock, what the label shows, its shop (null = every shop) and
 * whether it is that shop's default (one default per shop; setting one clears the others). Runs in the company scope.
 */
final class SaveLabelTemplate
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array{name: string, stock: string, branch_id?: string|null, is_default?: bool|null, options?: array<string, mixed>|null}  $data
     */
    public function handle(?LabelTemplate $template, array $data): LabelTemplate
    {
        if (! in_array($data['stock'], LabelStocks::keys(), true)) {
            throw ValidationException::withMessages(['stock' => 'Choose a label stock.']);
        }

        $branchId = $data['branch_id'] ?? null;
        if ($branchId !== null && ! Branch::query()->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => 'Choose a shop of this business.']);
        }

        return DB::transaction(function () use ($template, $data, $branchId) {
            $created = $template === null;
            $template ??= new LabelTemplate;
            $before = $created ? null : $template->only(['name', 'stock', 'branch_id', 'is_default', 'options']);

            $template->forceFill([
                'name' => trim($data['name']),
                'stock' => $data['stock'],
                'branch_id' => $branchId,
                'is_default' => (bool) ($data['is_default'] ?? false),
                'options' => LabelTemplate::normaliseOptions($data['options'] ?? null),
            ])->save();

            if ($template->is_default) {
                LabelTemplate::query()->where('id', '!=', $template->id)
                    ->where(fn ($q) => $branchId === null ? $q->whereNull('branch_id') : $q->where('branch_id', $branchId))
                    ->update(['is_default' => false]);
            }

            $this->audit->handle($created ? 'label_template.created' : 'label_template.updated', $template, $before,
                $template->only(['name', 'stock', 'branch_id', 'is_default', 'options']), ['name' => $template->name]);

            return $template;
        });
    }
}
