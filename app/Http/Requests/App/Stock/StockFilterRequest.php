<?php

namespace App\Http\Requests\App\Stock;

use App\Domain\Stock\Data\StockFilters;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The stock screens' lists (module 5.1). The route checks `stock.view`. Filters are read leniently (a bad value is
 * dropped, not an error: these are bookmarkable list URLs); a one-shop user is pinned to their shop and the shop
 * defaults to the one picked in the top bar.
 */
class StockFilterRequest extends FormRequest
{
    public const PER_PAGE = [25, 50, 100];

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

    public function filters(): StockFilters
    {
        $current = app(ResolveCurrentBranch::class)->handle($this->session());

        return StockFilters::fromRequest($this, app(CurrentCompany::class), $current?->id);
    }

    public function pageNumber(): int
    {
        $page = $this->query('page');

        return is_numeric($page) ? max(1, min(10_000, (int) $page)) : 1;
    }

    public function perPage(int $default = 50): int
    {
        return in_array((int) $this->query('perPage'), self::PER_PAGE, true) ? (int) $this->query('perPage') : $default;
    }
}
