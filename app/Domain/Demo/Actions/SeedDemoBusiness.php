<?php

namespace App\Domain\Demo\Actions;

use App\Domain\Demo\Builders\CashBuilder;
use App\Domain\Demo\Builders\CashOfficeBuilder;
use App\Domain\Demo\Builders\CatalogueBuilder;
use App\Domain\Demo\Builders\ComplianceBuilder;
use App\Domain\Demo\Builders\CustomerBuilder;
use App\Domain\Demo\Builders\LoyaltyBuilder;
use App\Domain\Demo\Builders\NewsBuilder;
use App\Domain\Demo\Builders\PromotionBuilder;
use App\Domain\Demo\Builders\PurchaseOrderBuilder;
use App\Domain\Demo\Builders\PurchaseReturnBuilder;
use App\Domain\Demo\Builders\SetupBuilder;
use App\Domain\Demo\Builders\StaffTimeBuilder;
use App\Domain\Demo\Builders\StockBuilder;
use App\Domain\Demo\Builders\StockTakeBuilder;
use App\Domain\Demo\Builders\SupplierBillingBuilder;
use App\Domain\Demo\Builders\TillHealthBuilder;
use App\Domain\Demo\Builders\TransferBuilder;
use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoJournal;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\StockBook;
use App\Domain\Reporting\Actions\GenerateDemoSales;
use App\Domain\Reporting\Actions\RebuildReports;
use App\Domain\Reporting\Demo\DemoShop;
use App\Domain\Reporting\ReportTables;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\Actions\ApplySyncChanges;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * `demo:seed`: a complete, realistic business for demos, through the real push path (`ApplySyncChanges`) so ids,
 * versions, scopes and the reporting tables are exactly as a till's would be. In order: the catalogue and set-up,
 * customers, the till sales (`GenerateDemoSales`, lines naming real products), then everything that follows from
 * trading (deliveries and supplier bills, stock, staff time, compliance, news, offers, loyalty, cash-ups, journals),
 * till health, and a rebuild of the reporting tables.
 *
 * Repeatable: every id and seq is derived, so a second run stores nothing twice. `fresh` first removes only the
 * rows the demo made (ForgetDemoData). Demo sales made before the catalogue existed are replaced automatically.
 * Never in production; never touches another business.
 */
final class SeedDemoBusiness
{
    public function __construct(
        private readonly ApplySyncChanges $apply,
        private readonly GenerateDemoSales $sales,
        private readonly RebuildReports $rebuild,
        private readonly ForgetDemoData $forget,
        private readonly TillHealthBuilder $health,
    ) {}

    /**
     * @param  (Closure(string): void)|null  $progress  called with each step's name
     * @return array{shops: int, rows: int, sales: int, removed: int}
     */
    public function handle(Company $company, int $days, bool $fresh = false, ?CarbonImmutable $now = null, ?Closure $progress = null): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo data is never generated in production.');
        }

        $business = new DemoBusiness($company, $this->shops($company), max(1, $days), $now ?? CarbonImmutable::now());
        $totals = ['shops' => count($business->shops), 'rows' => 0, 'sales' => 0, 'removed' => 0];

        if ($business->shops === []) {
            return $totals;
        }

        $step = fn (string $name) => $progress !== null ? $progress($name) : null;
        $oldest = null;

        if ($fresh || ForgetDemoData::hasProductlessSales($business->companyId)) {
            $step($fresh ? 'Removing earlier demo data' : 'Replacing demo sales made before the catalogue');
            $forgotten = $this->forget->handle($business->companyId, $fresh ? ForgetDemoData::STREAMS : [ForgetDemoData::STREAMS[1]]);
            [$totals['removed'], $oldest] = [$forgotten['rows'], $forgotten['oldestDay']];
        }

        $push = new DemoPush($this->apply, $business);
        $journal = new DemoJournal($push);

        $step('Catalogue, suppliers, staff and customers');
        (new CatalogueBuilder)->handle($business, $push);
        (new SetupBuilder)->handle($business, $push);
        (new CustomerBuilder)->handle($business, $push);
        $push->flush();

        $step('Till sales');
        $totals['sales'] = $this->sales->handle($company, $business->days, false, $business->now, rebuild: false)['sales'];

        $step('Deliveries, supplier bills, transfers and stock');
        $book = new StockBook;
        $deliveries = (new PurchaseOrderBuilder)->handle($business, $push, $book);
        (new SupplierBillingBuilder)->handle($business, $push, $journal, $deliveries);
        (new PurchaseReturnBuilder)->handle($business, $push, $journal, $book);
        (new TransferBuilder)->handle($business, $push, $book);
        (new StockTakeBuilder)->handle($business, $push, $book);
        (new StockBuilder)->handle($business, $push, $book);
        $push->flush();

        $step('Staff time, compliance, news, offers and loyalty');
        (new StaffTimeBuilder)->handle($business, $push);
        (new ComplianceBuilder)->handle($business, $push);
        (new NewsBuilder)->handle($business, $push);
        (new PromotionBuilder)->handle($business, $push);
        (new LoyaltyBuilder)->handle($business, $push);
        $push->flush();

        $step('Cash-ups, Z reports, banking and journals');
        $tillDays = (new CashBuilder)->handle($business, $push, $journal);
        (new CashOfficeBuilder)->handle($business, $push, $journal, $tillDays);
        $push->flush();

        $step('Till health');
        $this->health->handle($business, $push);
        $totals['rows'] = $push->flush();

        $step('Reporting tables');
        $from = $oldest !== null && $oldest < $business->from ? $oldest : $business->from;
        $started = now('UTC')->format('Y-m-d H:i:s');
        $this->rebuild->handle([$business->companyId], $from, $business->today);
        DB::table(ReportTables::DIRTY_DAYS)->where('company_id', $business->companyId)
            ->whereBetween('trading_day', [$from, $business->today])->where('marked_at', '<=', $started)->delete();

        return $totals;
    }

    /**
     * Active shops with active tills (main till first), by code, as demo sales use them.
     *
     * @return list<array{branch: Branch, shop: DemoShop}>
     */
    private function shops(Company $company): array
    {
        $shops = [];
        $branches = Branch::withoutCompanyScope()->where('company_id', $company->getKey())->where('is_active', true)->orderBy('code')->get();

        foreach ($branches as $branch) {
            $registers = Register::withoutCompanyScope()->where('branch_id', $branch->id)->where('is_active', true)
                ->orderByDesc('is_main_till')->orderBy('code')->get(['id', 'code'])
                ->map(fn (Register $r) => ['id' => (string) $r->id, 'code' => (string) $r->code])->values()->all();

            if ($registers !== []) {
                $shops[] = ['branch' => $branch, 'shop' => new DemoShop((string) $company->getKey(), (string) $branch->id, (string) $branch->code, $registers)];
            }
        }

        return $shops;
    }
}
