<?php

namespace App\Http\Requests\Store;

use App\Domain\OnlineStore\Data\CatalogueFilters;
use App\Domain\Shared\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The public store's product lists (OS-2: department and search): `q`, `sort`, `stock=1` (in stock only), `min` /
 * `max` (price in pounds) and `page`. A bad value is dropped, never an error page: links get shared and edited.
 */
class StoreListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function term(): string
    {
        return mb_substr(trim((string) $this->string('q')), 0, 80);
    }

    public function page(): int
    {
        $page = filter_var($this->query('page'), FILTER_VALIDATE_INT);

        return is_int($page) && $page > 0 && $page <= 10000 ? $page : 1;
    }

    public function filters(): CatalogueFilters
    {
        $sort = (string) $this->query('sort', CatalogueFilters::SORT_NAME);
        [$min, $max] = [self::price($this->query('min')), self::price($this->query('max'))];

        if ($min !== null && $max !== null && Money::compare($min, $max) > 0) {
            [$min, $max] = [$max, $min];
        }

        return new CatalogueFilters(
            sort: in_array($sort, CatalogueFilters::SORTS, true) ? $sort : CatalogueFilters::SORT_NAME,
            inStockOnly: $this->query('stock') === '1',
            minPrice: $min,
            maxPrice: $max,
        );
    }

    /**
     * The filters as the page echoes them.
     *
     * @return array{sort: string, stock: bool, min: string|null, max: string|null}
     */
    public function echo(): array
    {
        $filters = $this->filters();

        return ['sort' => $filters->sort, 'stock' => $filters->inStockOnly, 'min' => $filters->minPrice, 'max' => $filters->maxPrice];
    }

    private static function price(mixed $value): ?string
    {
        $value = is_string($value) ? trim(str_replace(['£', ','], '', $value)) : '';

        return preg_match('/^\d{1,7}(\.\d{1,2})?$/', $value) === 1 ? Money::normalise($value, 2) : null;
    }
}
