<?php

namespace App\Domain\Purchasing\Data;

use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Http\Request;

/**
 * The purchasing list filters (module 5.2), read leniently from the query string: an unknown value is ignored, never
 * an error. A one-shop user is always pinned to their shop, whatever the URL says.
 */
final readonly class PurchasingFilters
{
    public function __construct(
        public ?string $shop = null,
        public ?string $supplier = null,
        public ?string $status = null,
        public ?string $origin = null,
        public bool $pinned = false,
    ) {}

    /**
     * @param  list<string>  $statuses  the statuses this list knows
     */
    public static function from(Request $request, array $statuses = []): self
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();
        $text = fn (string $key) => is_string($request->query($key)) && $request->query($key) !== '' ? (string) $request->query($key) : null;
        $status = $text('status');
        $origin = $text('origin');

        return new self(
            $restricted ?? $text('shop'),
            $text('supplier'),
            in_array($status, $statuses, true) ? $status : null,
            in_array($origin, ['branch', 'headOffice'], true) ? $origin : null,
            $restricted !== null,
        );
    }

    /** @return array{shop: string|null, supplier: string|null, status: string|null, origin: string|null} */
    public function toArray(): array
    {
        return ['shop' => $this->shop, 'supplier' => $this->supplier, 'status' => $this->status, 'origin' => $this->origin];
    }
}
