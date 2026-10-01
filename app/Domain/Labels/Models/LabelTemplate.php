<?php

namespace App\Domain\Labels\Models;

use App\Domain\Labels\Support\LabelStocks;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Illuminate\Database\Eloquent\Model;

/**
 * A label layout (gap #6): the label stock it prints on ({@see LabelStocks}) and what each label shows. `branch_id`
 * null = offered to every shop; otherwise that shop's own. `is_default` = picked first when that shop prints.
 * Portal-only (the till keeps its own `labels.*` settings locally).
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $branch_id
 * @property string $name
 * @property string $stock
 * @property array<string, bool> $options
 * @property bool $is_default
 */
class LabelTemplate extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** What a label can show, named after the till's `labels.show_*` settings where one exists. */
    public const OPTIONS = ['show_unit_price', 'show_barcode', 'show_offer_name', 'show_shop_name', 'show_date', 'show_pmp', 'show_drs', 'highlight_offers'];

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['options' => 'array', 'is_default' => 'boolean'];
    }

    /**
     * Options with every member present (a missing one is on).
     *
     * @param  array<string, mixed>|null  $options
     * @return array<string, bool>
     */
    public static function normaliseOptions(?array $options): array
    {
        $out = [];
        foreach (self::OPTIONS as $key) {
            $out[$key] = (bool) ($options[$key] ?? true);
        }

        return $out;
    }
}
