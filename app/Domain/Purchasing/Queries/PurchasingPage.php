<?php

namespace App\Domain\Purchasing\Queries;

use App\Domain\Purchasing\Data\PurchasingFilters;
use App\Domain\Purchasing\Queries\Lists\CreditNoteRows;
use App\Domain\Purchasing\Queries\Lists\DeliveryRows;
use App\Domain\Purchasing\Queries\Lists\DocumentRows;
use App\Domain\Purchasing\Queries\Lists\InvoiceRows;
use App\Domain\Purchasing\Queries\Lists\OrderRows;
use App\Domain\Purchasing\Queries\Lists\PaymentRows;
use App\Domain\Purchasing\Queries\Lists\RebateRows;
use App\Domain\Purchasing\Queries\Lists\ReturnRows;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Props for the purchasing lists (module 5.2): one page per kind (orders, deliveries, invoices, credit notes,
 * returns, payments, rebates) with the tab counts, the kind's summary cards, filters and the rows. A one-shop user
 * sees only their shop's documents.
 */
final class PurchasingPage
{
    public const KINDS = ['orders', 'deliveries', 'invoices', 'credit-notes', 'returns', 'payments', 'rebates'];

    /** @return DocumentRows<covariant Model> */
    public static function rows(string $kind): DocumentRows
    {
        return match ($kind) {
            'orders' => new OrderRows,
            'deliveries' => new DeliveryRows,
            'invoices' => new InvoiceRows,
            'credit-notes' => new CreditNoteRows,
            'returns' => new ReturnRows,
            'payments' => new PaymentRows,
            'rebates' => new RebateRows,
            default => abort(404),
        };
    }

    /** @return array<string, mixed> */
    public static function for(Request $request, string $kind): array
    {
        $rows = self::rows($kind);
        $filters = PurchasingFilters::from($request, $rows->statuses());

        return [
            'kind' => $kind,
            'tabs' => self::counts($filters->shop),
            'stats' => $rows->stats($rows->scoped($filters->shop)),
            'filters' => $filters->toArray(),
            'statuses' => $rows->statuses(),
            'rows' => $rows->page(TableQuery::from($request)->defaultPerPage(25), $filters),
            ...self::shared(),
        ];
    }

    /**
     * Shops, suppliers and what the user may do, shared by the purchasing screens.
     *
     * @return array{shops: list<array{id: string, name: string, code: string}>, suppliers: list<array{id: string, name: string}>, oneShop: bool, can: array{manage: bool}}
     */
    public static function shared(): array
    {
        $tenancy = app(CurrentCompany::class);
        $restricted = $tenancy->restrictedBranchId();

        return [
            'shops' => Branch::query()->when($restricted !== null, fn ($q) => $q->whereKey($restricted))->orderBy('name')->get(['id', 'name', 'code'])
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])->values()->all(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => (string) $s->name])->values()->all(),
            'oneShop' => $restricted !== null,
            'can' => ['manage' => self::canManage()],
        ];
    }

    /** Head-office orders: `purchasing.manage` and every shop (not a one-shop manager). */
    public static function canManage(): bool
    {
        $tenancy = app(CurrentCompany::class);

        return $tenancy->can(Ability::PurchasingManage) && $tenancy->restrictedBranchId() === null;
    }

    /** @return array<string, int> */
    private static function counts(?string $shop): array
    {
        $counts = [];

        foreach (self::KINDS as $kind) {
            $counts[$kind] = self::rows($kind)->scoped($shop)->count();
        }

        return $counts;
    }
}
