<?php

namespace App\Domain\MasterCatalogue\Actions;

use App\Domain\MasterCatalogue\Enums\MasterSource;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Support\MasterFields;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits one master catalogue product (admin form, CSV row, approved contribution, starter set). Values go
 * through MasterFields; a barcode is on one row only. Keys the input leaves out keep their value (a CSV row may carry
 * only a price). A row that changes nothing is not written and keeps its source; a change records where it came from
 * (`source`, `source_ref`) and when (`updated_at`).
 *
 * Returns the row and 'created', 'updated' or 'unchanged'.
 */
final class SaveMasterProduct
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $input  a subset of MasterProduct::FIELDS
     * @return array{0: MasterProduct, 1: 'created'|'updated'|'unchanged'}
     *
     * @throws ValidationException
     */
    public function handle(?MasterProduct $product, array $input, MasterSource $source, ?string $sourceRef = null, bool $audit = true): array
    {
        $created = $product === null;
        $input = array_intersect_key($input, array_flip(MasterProduct::FIELDS));

        if ($created) {
            $input += ['barcode' => '', 'name' => ''];
        }

        [$values, $errors] = MasterFields::clean($input);

        if (! isset($errors['barcode']) && isset($values['barcode']) && MasterProduct::query()->where('barcode', $values['barcode'])
            ->when(! $created, fn ($q) => $q->whereKeyNot($product->id))->exists()) {
            $errors['barcode'] = "Barcode {$values['barcode']} is already in the catalogue.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $product ??= new MasterProduct(['age_rule' => 'none']);
        $before = $created ? null : $product->only(array_keys($values));
        $product->fill($values);

        if (! $created && ! $product->isDirty()) {
            return [$product, 'unchanged'];
        }

        $product->forceFill(['source' => $source, 'source_ref' => $sourceRef === null ? null : mb_substr($sourceRef, 0, 255)])->save();

        if ($audit) {
            $this->audit->handle(
                $created ? 'master_product.created' : 'master_product.updated',
                $product,
                $before,
                $product->only(array_keys($values)),
                ['name' => $product->name, 'barcode' => $product->barcode],
            );
        }

        return [$product, $created ? 'created' : 'updated'];
    }
}
