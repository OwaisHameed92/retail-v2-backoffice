<?php

namespace App\Http\Requests\App\Labels;

use App\Domain\Labels\Models\LabelQueueItem;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Anything done to one shop's shelf labels (gap #6). Route: `company.can:labels.print`. A one-shop user may name only
 * their own shop (403 otherwise); another business's shop is not found. Covers adding (product_ids / department_id /
 * supplier_id), the queue changes (ids or `all` waiting labels, copies) and printing (template_id, skip, mark_printed).
 */
class LabelShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return $restricted === null || $this->input('branch_id') === $restricted;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'string', 'size:26'],
            'product_ids' => ['sometimes', 'array', 'max:200'],
            'product_ids.*' => ['string', 'size:26'],
            'department_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'supplier_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'ids' => ['sometimes', 'array', 'max:2000'],
            'ids.*' => ['string', 'size:26'],
            'all' => ['sometimes', 'boolean'],
            'copies' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'template_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'skip' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'mark_printed' => ['sometimes', 'boolean'],
        ];
    }

    public function shop(): Branch
    {
        return Branch::query()->findOrFail($this->validated('branch_id'));
    }

    /**
     * The labels named, or with `all` every label waiting in the shop (oldest first).
     *
     * @return list<string>
     */
    public function ids(): array
    {
        if ($this->boolean('all')) {
            return LabelQueueItem::query()->where('branch_id', $this->validated('branch_id'))->where('pending', true)
                ->orderBy('queued_at')->limit(2000)->pluck('id')->map(fn ($id) => (string) $id)->all();
        }

        return array_values(array_map('strval', (array) $this->validated('ids', [])));
    }
}
