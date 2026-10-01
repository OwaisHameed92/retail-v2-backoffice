<?php

namespace App\Domain\MasterCatalogue\Actions;

use App\Domain\MasterCatalogue\Data\PriceRule;
use App\Domain\MasterCatalogue\Enums\StarterPack;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use Illuminate\Validation\ValidationException;

/**
 * Onboarding "starter pack": adds the master catalogue's starter lines (`in_starter_packs`) of the departments the
 * owner kept ticked for their kind of shop, through AddFromCatalogue (so tills get them at their next pull and
 * barcodes the business already has are skipped).
 */
final class AddStarterPack
{
    public function __construct(private readonly AddFromCatalogue $add) {}

    /**
     * @param  list<string>  $departments  catalogue department names to include
     * @param  array<string, string|null>  $mapping  catalogue department name => business department id
     * @return array{created: int, existing: list<string>, failed: list<array{barcode: string, name: string, message: string}>}
     */
    public function handle(StarterPack $pack, array $departments, PriceRule $rule, array $mapping = []): array
    {
        $barcodes = $departments === [] ? [] : MasterProduct::query()->current()->where('in_starter_packs', true)
            ->whereIn('department', $departments)->orderBy('department')->orderBy('name')
            ->limit(AddFromCatalogue::MAX_ITEMS)->pluck('barcode')->all();

        if ($barcodes === []) {
            throw ValidationException::withMessages(['departments' => 'Tick at least one department with products in it.']);
        }

        return $this->add->handle(
            array_map(fn (string $code) => ['barcode' => $code], $barcodes),
            $rule,
            array_intersect_key($mapping, array_flip($departments)),
            'starterPack:'.$pack->value,
        );
    }
}
