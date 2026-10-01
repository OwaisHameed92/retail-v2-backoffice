<?php

namespace App\Domain\Labels\Support;

use App\Domain\Labels\Models\LabelTemplate;
use Illuminate\Database\Eloquent\Builder;

/**
 * The templates a shop can print with (its own and the every-shop ones) and which comes first: the shop's default,
 * then an every-shop default, then the built-in one (A4 3 × 8, everything shown). Runs in the company scope.
 */
final class LabelTemplates
{
    public const BUILT_IN = 'builtin';

    /**
     * @return list<array{id: string, name: string, stock: string, branchId: string|null, isDefault: bool, options: array<string, bool>}>
     */
    public static function forShop(string $branchId): array
    {
        $rows = LabelTemplate::query()->where(fn (Builder $q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->orderByDesc('is_default')->orderByRaw('case when branch_id is null then 1 else 0 end')->orderBy('name')->get();

        $list = $rows->map(fn (LabelTemplate $t) => self::row($t))->values()->all();
        $list[] = self::builtIn();

        return $list;
    }

    /**
     * The template to print with: the one chosen (of this shop or every shop), else the shop's default.
     *
     * @return array{id: string, name: string, stock: string, branchId: string|null, isDefault: bool, options: array<string, bool>}
     */
    public static function pick(string $branchId, ?string $templateId): array
    {
        $list = self::forShop($branchId);

        foreach ($list as $template) {
            if ($templateId !== null && $template['id'] === $templateId) {
                return $template;
            }
        }

        return $list[0];
    }

    /**
     * @return array{id: string, name: string, stock: string, branchId: string|null, isDefault: bool, options: array<string, bool>}
     */
    public static function row(LabelTemplate $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'stock' => $t->stock, 'branchId' => $t->branch_id,
            'isDefault' => $t->is_default, 'options' => LabelTemplate::normaliseOptions($t->options),
        ];
    }

    /**
     * @return array{id: string, name: string, stock: string, branchId: string|null, isDefault: bool, options: array<string, bool>}
     */
    public static function builtIn(): array
    {
        return [
            'id' => self::BUILT_IN, 'name' => 'Standard (built in)', 'stock' => LabelStocks::DEFAULT, 'branchId' => null, 'isDefault' => false,
            'options' => LabelTemplate::normaliseOptions([]),
        ];
    }
}
