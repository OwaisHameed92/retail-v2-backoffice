<?php

namespace App\Domain\Compliance\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Enums\ProductRecallStatus;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductRecall;
use App\Domain\TillData\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raise or edit a product recall on the portal (module 5.7). `ProductRecall` is hub-owned (ownership.json), so the
 * save goes through the HubOwnedRow model path and every shop's till receives it in its next pull (company-wide,
 * `scope` null). A new recall is open, raised now; its `raisedByUserId` stays blank (a portal user is not a till
 * user: the portal's audit log says who). An edit raises `row_version` by one; a save that changes nothing writes
 * nothing. Only the text fields (FIELDS) are the portal's: closing, reopening, returns and the note are done at a till
 * and never sent in an update (PullPayload::TILL_KEEPS_ON_UPDATE, ANSWERS-2026-10-06 Q3), so a closed recall's text
 * can be corrected too.
 */
final class SaveProductRecall
{
    public const FIELDS = ['reference', 'product_id', 'product_name', 'batch_code', 'expiry_from', 'expiry_to', 'source', 'reason', 'supplier_id'];

    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data  snake_case columns of FIELDS
     *
     * @throws ValidationException
     */
    public function handle(Company $company, ?string $recallId, array $data): ProductRecall
    {
        return $this->tenancy->runAs($company, fn (): ProductRecall => DB::transaction(function () use ($recallId, $data): ProductRecall {
            $recall = $recallId === null ? $this->fresh() : ProductRecall::query()->lockForUpdate()->findOrFail($recallId);

            $values = $this->clean(Arr::only($data, self::FIELDS), $recall);
            $before = $recall->exists ? Arr::only($recall->attributesToArray(), self::FIELDS) : null;
            $recall->forceFill($values);
            $after = Arr::only($recall->attributesToArray(), self::FIELDS);
            $changed = $before === null ? array_keys($after) : array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

            if ($before !== null && $changed === []) {
                return $recall;
            }

            if ($before !== null) {
                $recall->row_version = (int) $recall->row_version + 1;
            }

            $recall->save();

            $this->audit->handle(
                $before === null ? 'recall.raised' : 'recall.updated',
                $recall,
                $before === null ? null : Arr::only($before, $changed),
                Arr::only($after, $before === null ? ['reference', 'product_name', 'batch_code'] : $changed),
                ['reference' => $recall->reference],
            );

            return $recall;
        }));
    }

    private function fresh(): ProductRecall
    {
        $now = CarbonImmutable::now('UTC');

        return (new ProductRecall)->forceFill([
            'reference' => '', 'product_id' => '', 'product_name' => '', 'batch_code' => '', 'expiry_from' => null, 'expiry_to' => null,
            'source' => '', 'reason' => '', 'status' => ProductRecallStatus::Open, 'supplier_id' => '', 'returned_qty' => '0',
            'raised_by_user_id' => '', 'raised_at' => $now, 'closed_by_user_id' => '', 'closed_at' => null, 'note' => '', 'scope' => null,
        ]);
    }

    /**
     * Trimmed strings (never null: the till's columns are non-null strings), dates as `Y-m-d`, the product name taken
     * from the catalogue when a product is picked, and a reference made up when none is given.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function clean(array $data, ProductRecall $recall): array
    {
        $values = [];

        foreach ($data as $key => $value) {
            $values[$key] = in_array($key, ['expiry_from', 'expiry_to'], true)
                ? ($value === null || $value === '' ? null : CarbonImmutable::parse((string) $value)->format('Y-m-d'))
                : trim((string) ($value ?? ''));
        }

        if (($values['product_id'] ?? '') !== '') {
            $product = Product::query()->find($values['product_id'], ['id', 'name']);
            throw_if($product === null, ValidationException::withMessages(['product_id' => 'Pick a product from your catalogue.']));
            $values['product_name'] = ($values['product_name'] ?? '') !== '' ? $values['product_name'] : (string) $product->name;
        }

        if (($values['supplier_id'] ?? '') !== '' && ! Supplier::query()->whereKey($values['supplier_id'])->exists()) {
            throw ValidationException::withMessages(['supplier_id' => 'Pick one of your suppliers.']);
        }

        if (($values['product_name'] ?? ($recall->exists ? (string) $recall->product_name : '')) === '') {
            throw ValidationException::withMessages(['product_name' => 'Enter the product being recalled.']);
        }

        if (($values['expiry_from'] ?? null) !== null && ($values['expiry_to'] ?? null) !== null && $values['expiry_from'] > $values['expiry_to']) {
            throw ValidationException::withMessages(['expiry_to' => 'The last date must be on or after the first.']);
        }

        if (! $recall->exists && ($values['reference'] ?? '') === '') {
            $values['reference'] = 'RC-'.CarbonImmutable::now('Europe/London')->format('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        }

        return $values;
    }
}
