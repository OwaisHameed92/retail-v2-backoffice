<?php

namespace App\Domain\Pharmacy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Enums\MedicineClassificationClass;
use App\Domain\TillData\Models\MedicineClassification;
use App\Domain\TillData\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets a product's medicine class (module 5.10): General Sale, Pharmacy-only or Prescription-only, with an optional
 * note. MedicineClassification is hub-owned (ownership.json), so the save goes through the model (HubOwnedRow): a new
 * row gets a ULID and rowVersion 1, an edit raises rowVersion by one, and every till receives it in its next pull.
 * One class per product: an existing row (or a removed one, brought back) is updated, never duplicated. `null`
 * removes the class (soft delete, pulled as `D`). Nothing is written when nothing changed. Audited.
 *
 *     app(SaveMedicineClass::class)->handle($company, $productId, MedicineClassificationClass::PharmacyOnly, 'Max 2 packs');
 */
final class SaveMedicineClass
{
    public const NOTE_MAX = 500;

    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @return bool whether anything changed
     *
     * @throws ValidationException
     */
    public function handle(Company $company, string $productId, ?MedicineClassificationClass $class, ?string $note = null): bool
    {
        $note = trim((string) $note);

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['note' => 'Keep the note to '.self::NOTE_MAX.' characters.']);
        }

        return $this->tenancy->runAs($company, fn (): bool => DB::transaction(function () use ($productId, $class, $note): bool {
            $product = Product::query()->find($productId);

            if ($product === null) {
                throw ValidationException::withMessages(['productId' => 'Choose one of your products.']);
            }

            $row = MedicineClassification::withTrashed()->where('product_id', $product->id)->orderBy('deleted_at')->first();
            $before = $row === null || $row->trashed() ? null : ['class' => $row->class?->value, 'note' => (string) $row->note];

            if ($class === null) {
                if ($before === null) {
                    return false;
                }

                $row->delete();
                $this->audit->handle('medicine_class.removed', $row, $before, null, ['product' => $product->name]);

                return true;
            }

            $after = ['class' => $class->value, 'note' => $note];

            if ($before === $after) {
                return false;
            }

            $row ??= new MedicineClassification;
            $row->forceFill(['product_id' => $product->id, 'class' => $class, 'note' => $note, 'deleted_at' => null]);

            if ($row->exists) {
                $row->row_version = (int) $row->row_version + 1;
            }

            $row->save();
            $this->audit->handle($before === null ? 'medicine_class.created' : 'medicine_class.updated', $row, $before, $after, ['product' => $product->name]);

            return true;
        }));
    }
}
