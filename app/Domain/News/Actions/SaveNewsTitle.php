<?php

namespace App\Domain\News\Actions;

use App\Domain\News\Support\NewsAccess;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\NewsTitleFrequency;
use App\Domain\TillData\Models\NewsTitle;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a news title on the portal (module 5.8). `NewsTitle` is hub-owned with its own `branch_id`: a
 * title for one shop (branch_id = the shop) reaches only that shop's tills, a title for every shop (null) reaches
 * every till. The save goes through the model (HubOwnedRow), so the pull sends it; moving a title to another shop
 * (or from every shop to one) gives each shop it left a `D` (BranchDepartures, ANSWERS-2026-09-29-b A.3).
 *
 * Checks what the form cannot: the shop, supplier and linked product are this business's; the user may name that
 * shop (a one-shop user: only their own, never every shop) and may edit the title as it is now; no other live title
 * of the same name is on the same shop(s). Nothing changed → nothing written. An edit raises `row_version` by one.
 *
 * @phpstan-type TitleInput array{name: string, publisher?: string|null, frequency: string, supplier_id: string, cover_price: string, linked_product_id?: string|null, linked_barcode?: string|null, branch_id?: string|null, is_active?: bool|null}
 */
final class SaveNewsTitle
{
    private const FIELDS = ['name', 'publisher', 'frequency', 'supplier_id', 'cover_price', 'linked_product_id', 'linked_barcode', 'branch_id', 'is_active'];

    public function __construct(private readonly RecordAudit $audit) {}

    /** @param TitleInput $input */
    public function handle(?NewsTitle $title, array $input): NewsTitle
    {
        $created = $title === null;

        if (! $created && ! NewsAccess::mayEdit($title)) {
            throw new AuthorizationException('This title is for every shop; only a user of every shop can change it.');
        }

        $shop = ($input['branch_id'] ?? null) ?: null;

        if (! NewsAccess::mayUseShop($shop)) {
            throw new AuthorizationException('You can only keep titles for your own shop.');
        }

        $attributes = $this->attributes($input, $shop, $title);
        $this->check($title, $attributes);

        return DB::transaction(function () use ($title, $attributes, $created) {
            $title ??= new NewsTitle;
            $before = $created ? null : $this->snapshot($title);
            $title->forceFill($attributes);
            $after = $this->snapshot($title);

            // Compared as the model reads them: "3.00" and a stored 3 are the same price.
            if ($before === $after) {
                return $title->refresh();
            }

            if (! $created) {
                $title->row_version = (int) $title->row_version + 1;
            }

            $moved = $before !== null && $before['branch_id'] !== $after['branch_id'];
            $title->save();
            $changed = $created ? array_keys($after) : array_keys(array_diff_assoc($after, (array) $before));

            $this->audit->handle(
                $created ? 'news_title.created' : ($moved ? 'news_title.moved' : 'news_title.updated'),
                $title,
                $before === null ? null : array_intersect_key($before, array_flip($changed)),
                array_intersect_key($after, array_flip($changed)),
                ['name' => $title->name],
            );

            return $title;
        });
    }

    /**
     * @param  TitleInput  $input
     * @return array<string, mixed>
     */
    private function attributes(array $input, ?string $shop, ?NewsTitle $title): array
    {
        $product = trim((string) ($input['linked_product_id'] ?? ''));
        $barcode = trim((string) ($input['linked_barcode'] ?? ''));

        if ($product !== '' && $barcode === '') {
            $barcode = (string) ProductBarcode::query()->where('product_id', $product)->orderByDesc('is_primary')->orderBy('barcode')->value('barcode');
        }

        return [
            'name' => trim($input['name']),
            'publisher' => trim((string) ($input['publisher'] ?? '')),
            'frequency' => NewsTitleFrequency::from($input['frequency']),
            'supplier_id' => $input['supplier_id'],
            'cover_price' => Money::normalise($input['cover_price']),
            'linked_product_id' => $product,
            'linked_barcode' => $barcode,
            'branch_id' => $shop,
            'is_active' => $input['is_active'] ?? $title->is_active ?? true,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function check(?NewsTitle $title, array $attributes): void
    {
        if ($attributes['branch_id'] !== null && ! Branch::query()->whereKey($attributes['branch_id'])->exists()) {
            self::fail('branch_id', 'Choose one of your shops.');
        }

        if (! Supplier::query()->whereKey($attributes['supplier_id'])->exists()) {
            self::fail('supplier_id', 'Choose one of your suppliers.');
        }

        if ($attributes['linked_product_id'] !== '' && ! Product::query()->whereKey($attributes['linked_product_id'])->exists()) {
            self::fail('linked_product_id', 'Choose one of your products.');
        }

        // The same name twice on a shop's till (its own title or an every-shop one) would be two buttons for one paper.
        $clash = NewsTitle::query()
            ->when($title !== null, fn ($q) => $q->whereKeyNot($title->id))
            ->where('is_active', true)
            ->whereRaw('lower(name) = ?', [mb_strtolower((string) $attributes['name'])])
            ->when($attributes['branch_id'] !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', '')->orWhere('branch_id', $attributes['branch_id'])))
            ->exists();

        if ($clash && $attributes['is_active']) {
            self::fail('name', $attributes['branch_id'] === null
                ? 'A title with this name is already on sale at one of your shops.'
                : 'A title with this name is already on sale at this shop.');
        }
    }

    /** @return array<string, string|null> */
    private function snapshot(NewsTitle $title): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $value = $title->getAttribute($field);
            $values[$field] = match (true) {
                $value instanceof NewsTitleFrequency => $value->value,
                is_bool($value) => $value ? 'true' : 'false',
                default => $value === null ? null : (string) $value,
            };
        }

        return $values;
    }

    public static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
