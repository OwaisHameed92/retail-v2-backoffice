<?php

namespace App\Domain\TillData\Sync;

use App\Domain\TillData\Registry\EntityDefinition;
use Illuminate\Support\Facades\DB;

/**
 * Members of a hub-owned row that the till owns (till 0.1.85, WORKSHOP-BIKEBOOK.md: `Product.workshopExposure` is
 * set on the till's product form; "Product is hub-owned but the till edits this field, so accept it from push").
 *
 * - Absent or null (every till before 0.1.85, and a payload that simply leaves it out) means "no change": the column
 *   stays null on a new row and keeps the stored value on an existing one, never the type's default (PayloadMapper,
 *   carry()). So an older till's unchanged push of a product is still an echo.
 * - Left out of the row's content hash while null (RowHash), so every hash stored before 0.1.85 stays valid.
 * - Never pulled while null (PullPayload::KEPT_WHEN_BLANK): a till keeps its own value.
 * - A till change of the row that loses to a newer portal (or other shop's) version is still recorded as a conflict
 *   and keeps the stored hub fields, but its till-owned members are taken (salvage()): the portal's name, price… stay,
 *   the till's exposure is stored, and the row is queued for every till.
 */
final class TillOwnedFields
{
    /** entity => [member => column] */
    public const FIELDS = ['Product' => ['workshopExposure' => 'workshop_exposure']];

    public static function isMember(string $entity, string $member): bool
    {
        return isset(self::FIELDS[$entity][$member]);
    }

    /** @return list<string> */
    public static function columns(EntityDefinition $def): array
    {
        return array_values(self::FIELDS[$def->entity] ?? []);
    }

    /**
     * The incoming row with every till-owned column it does not carry filled from the stored row.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>
     */
    public static function carry(EntityDefinition $def, array $row, ?array $current): array
    {
        foreach (self::columns($def) as $column) {
            if (($row[$column] ?? null) === null && $current !== null) {
                $row[$column] = $current[$column] ?? null;
            }
        }

        return $row;
    }

    /**
     * After a till change lost to the stored row: the till-owned values it carries that differ from the stored ones,
     * as the column update for that row (content hash recomputed, queued for every till), or null when nothing differs.
     *
     * @param  array<string, mixed>  $incoming  the mapped row
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>|null
     */
    public static function salvage(EntityDefinition $def, array $incoming, array $current): ?array
    {
        $changed = [];

        foreach (self::columns($def) as $column) {
            $value = $incoming[$column] ?? null;

            if ($value !== null && $value !== ($current[$column] ?? null)) {
                $changed[$column] = $value;
            }
        }

        if ($changed === []) {
            return null;
        }

        $stored = (array) DB::table($def->table)->where('id', $current['id'])->first();

        return [...$changed, 'hub_hash' => RowHash::of($def, [...$stored, ...$changed]), 'hub_version' => null, 'origin_branch_id' => null];
    }
}
