<?php

namespace App\Domain\Purchasing\Reorder;

use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Http\Request;

/**
 * Filters of the reorder suggestions (module 6.4). A one-shop user always gets their own shop. `view`: `order` (lines
 * to order, the default), `attention` (lines worth a look: running out, selling faster or slower, short life,
 * negative stock, seasonal) or `all` (every product linked to a supplier).
 */
final readonly class ReorderFilters
{
    public const VIEWS = ['order', 'attention', 'all'];

    public function __construct(
        public ?string $shop = null,
        public ?string $supplier = null,
        public ?string $department = null,
        public string $view = 'order',
        public ?string $search = null,
    ) {}

    public static function from(Request $request): self
    {
        $text = function (string $key, int $max = 64) use ($request): ?string {
            $value = $request->query($key);

            return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
        };
        $view = $text('view');

        return self::make($text('shop'), $text('supplier'), $text('department'), in_array($view, self::VIEWS, true) ? $view : 'order', $text('q', 80));
    }

    public static function make(?string $shop = null, ?string $supplier = null, ?string $department = null, string $view = 'order', ?string $search = null): self
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return new self($restricted ?? $shop, $supplier, $department, $view, $search);
    }

    /**
     * @return array{shop: string|null, supplier: string|null, department: string|null, view: string, q: string|null}
     */
    public function toArray(): array
    {
        return ['shop' => $this->shop, 'supplier' => $this->supplier, 'department' => $this->department, 'view' => $this->view, 'q' => $this->search];
    }
}
