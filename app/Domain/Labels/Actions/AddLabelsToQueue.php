<?php

namespace App\Domain\Labels\Actions;

use App\Domain\Labels\Enums\LabelReason;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductSupplier;
use Illuminate\Validation\ValidationException;

/**
 * Adds products to a shop's label queue by hand (gap #6): chosen products (found by name, code or barcode), every
 * active product of a department, or every active product a supplier supplies. Deduped like the automatic queue.
 * Runs in the company scope (the caller passes a shop of the current business). Returns how many were queued.
 */
final class AddLabelsToQueue
{
    public const MAX = 1000;

    public function __construct(private readonly QueueLabels $queue, private readonly RecordAudit $audit) {}

    /**
     * @param  array{product_ids?: list<string>|null, department_id?: string|null, supplier_id?: string|null}  $selection
     */
    public function handle(Branch $branch, array $selection): int
    {
        if (! $branch->is_active) {
            throw ValidationException::withMessages(['branch_id' => 'That shop is closed.']);
        }

        $query = Product::query()->where('is_active', true);
        [$by, $value] = match (true) {
            ($selection['product_ids'] ?? []) !== [] => ['products', $query->whereIn('id', $selection['product_ids'] ?? [])],
            ($selection['department_id'] ?? null) !== null => ['department', $query->where('department_id', $selection['department_id'])],
            ($selection['supplier_id'] ?? null) !== null => ['supplier', $query->whereIn('id', ProductSupplier::query()->select('product_id')->where('supplier_id', $selection['supplier_id']))],
            default => throw ValidationException::withMessages(['product_ids' => 'Choose products, a department or a supplier.']),
        };

        $ids = $value->orderBy('name')->limit(self::MAX + 1)->pluck('id')->map(fn ($id) => (string) $id)->all();

        if ($ids === []) {
            throw ValidationException::withMessages([$by === 'products' ? 'product_ids' : "{$by}_id" => 'No products on sale match that choice.']);
        }

        if (count($ids) > self::MAX) {
            throw ValidationException::withMessages([$by === 'products' ? 'product_ids' : "{$by}_id" => 'That is more than '.self::MAX.' products. Add them in smaller groups.']);
        }

        $queued = $this->queue->handle($branch->company_id, $ids, [$branch->id], LabelReason::Manual, match ($by) {
            'department' => 'Whole department',
            'supplier' => 'Supplier\'s products',
            default => null,
        });

        $this->audit->handle('labels.queued', $branch, null, ['count' => $queued], ['by' => $by, 'shop' => $branch->name]);

        return $queued;
    }
}
