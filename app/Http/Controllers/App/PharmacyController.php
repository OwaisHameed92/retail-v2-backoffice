<?php

namespace App\Http\Controllers\App;

use App\Domain\Cash\Support\CashLookup;
use App\Domain\Pharmacy\Actions\SaveMedicineClass;
use App\Domain\Pharmacy\Queries\DispensingSummary;
use App\Domain\Pharmacy\Queries\MedicineClassList;
use App\Domain\Pharmacy\Support\ServiceModules;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\DispensingRecordExemption;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Cash\CashFilterRequest;
use App\Http\Requests\App\Pharmacy\MedicineClassRequest;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pharmacy on the tenant portal (module 5.10, `company.can:pharmacy.view`), only for businesses that use it
 * (ServiceModules; else 404): dispensing records, read only (branch-owned), and medicine classes, which the portal
 * edits (hub-owned; `catalogue.manage`, not a one-shop user) and every till receives in its next pull.
 */
class PharmacyController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function dispensing(CashFilterRequest $request): Response
    {
        $this->ensureUsed();
        $filters = $request->filters();
        $charge = in_array($request->query('charge'), DispensingSummary::CHARGES, true) ? (string) $request->query('charge') : null;
        $exemption = DispensingRecordExemption::tryFrom((string) $request->query('exemption'))?->value;

        return Inertia::render('app/pharmacy/dispensing', [
            ...DispensingSummary::for($request, $filters, $charge, $exemption),
            'filters' => $filters->toArray(),
            'options' => CashLookup::options($filters),
        ]);
    }

    public function medicines(Request $request): Response
    {
        $this->ensureUsed();

        return Inertia::render('app/pharmacy/medicines', [
            ...MedicineClassList::for($request),
            'canEdit' => $this->tenancy->can('catalogue.manage') && $this->tenancy->restrictedBranchId() === null,
        ]);
    }

    public function saveMedicine(MedicineClassRequest $request, string $product, SaveMedicineClass $save): RedirectResponse
    {
        $this->ensureUsed();
        $changed = $save->handle($this->tenancy->require(), $product, $request->medicineClass(), $request->validated('note'));

        return back()->with('success', $changed ? 'Medicine class saved. Your tills get it at their next sync.' : 'Nothing to change.');
    }

    public function removeMedicine(CompanyWideWriteRequest $request, string $product, SaveMedicineClass $save): RedirectResponse
    {
        $this->ensureUsed();
        $changed = $save->handle($this->tenancy->require(), $product, null);

        return back()->with('success', $changed ? 'Medicine class removed. Your tills get the change at their next sync.' : 'Nothing to change.');
    }

    private function ensureUsed(): void
    {
        abort_unless(ServiceModules::pharmacy($this->tenancy->require()), 404);
    }
}
