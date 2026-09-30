<?php

namespace App\Http\Controllers\App;

use App\Domain\Purchasing\Queries\DocumentDetail;
use App\Domain\Purchasing\Queries\OrderDetail;
use App\Domain\Purchasing\Queries\PurchasingPage;
use App\Domain\Purchasing\Queries\SupplierStatement;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\Supplier;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchasing on the tenant portal (module 5.2, `company.can:purchasing.view`), read only: purchase orders, deliveries
 * (GRNs), supplier invoices, credit notes, purchase returns, payments, rebates and supplier statements, all the
 * shops' own documents. A one-shop user sees only their shop (another shop's document is "not found").
 * Head-office orders are drafted in HeadOfficeOrderController.
 */
class PurchasingController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function home(): RedirectResponse
    {
        return redirect()->route('app.purchasing.index', 'orders');
    }

    public function index(Request $request, string $kind): Response
    {
        return Inertia::render('app/purchasing/index', PurchasingPage::for($request, $kind));
    }

    public function order(string $order): Response
    {
        return Inertia::render('app/purchasing/order', [...PurchasingPage::shared(), ...OrderDetail::for($this->visible(PurchaseOrder::query()->findOrFail($order)))]);
    }

    public function document(string $kind, string $document): Response
    {
        $model = DocumentDetail::MODELS[$kind] ?? abort(404);

        return Inertia::render('app/purchasing/document', DocumentDetail::for($kind, $this->visible($model::query()->findOrFail($document))));
    }

    public function statements(Request $request): Response
    {
        $shop = $this->shop($request);
        $balances = SupplierStatement::balances($shop);

        return Inertia::render('app/purchasing/statements', [
            'balances' => $balances, 'summary' => SupplierStatement::summary($balances), 'shop' => $shop, ...PurchasingPage::shared(),
        ]);
    }

    public function statement(Request $request, string $supplier): Response
    {
        $shop = $this->shop($request);
        [$from, $to] = SupplierStatement::period($request->query('from'), $request->query('to'));

        return Inertia::render('app/purchasing/statement', [
            ...SupplierStatement::for(Supplier::query()->withTrashed()->findOrFail($supplier), $shop, $from, $to), 'shop' => $shop, ...PurchasingPage::shared(),
        ]);
    }

    /**
     * @template T of Model
     *
     * @param  T  $row
     * @return T
     */
    private function visible(Model $row): Model
    {
        $restricted = $this->tenancy->restrictedBranchId();
        abort_if($restricted !== null && $row->getAttribute('branch_id') !== $restricted, 404);

        return $row;
    }

    private function shop(Request $request): ?string
    {
        $shop = $request->query('shop');

        return $this->tenancy->restrictedBranchId() ?? (is_string($shop) && $shop !== '' ? $shop : null);
    }
}
