<?php

namespace App\Http\Controllers\App;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Queries\ChartOfAccounts;
use App\Domain\Accounts\Queries\ExpenseList;
use App\Domain\Accounts\Queries\FinancialStatements;
use App\Domain\Accounts\Queries\FixedAssetList;
use App\Domain\Accounts\Queries\JournalList;
use App\Domain\Accounts\Queries\TrialBalance;
use App\Domain\Accounts\Queries\VatReturnHelper;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\JournalEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Accounts\AccountsFilterRequest;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Accounts and VAT of the tenant portal (module 5.5), read only: accounts, journals, expenses, VAT returns and fixed
 * assets are the tills' (ownership.json: Account hub-owned but made on each till; the rest branch-owned). Chart of
 * accounts by code, journals, trial balance, P&L, balance sheet, expenses, the VAT return helper and fixed assets.
 * A one-shop user sees only their shop.
 */
class AccountsController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(AccountsFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/accounts/chart', $filters, ChartOfAccounts::for($filters));
    }

    public function journals(AccountsFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/accounts/journals', $filters, JournalList::for($request, $filters));
    }

    public function journal(AccountsFilterRequest $request, string $entry): Response
    {
        $row = JournalEntry::query()->findOrFail($entry);
        $restricted = $this->tenancy->restrictedBranchId();
        abort_if($restricted !== null && $row->branch_id !== $restricted, 404);

        return Inertia::render('app/accounts/journal', JournalList::show($row, $request->filters()));
    }

    public function trialBalance(AccountsFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/accounts/trial-balance', $filters, TrialBalance::for($filters));
    }

    public function profitAndLoss(AccountsFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/accounts/profit-and-loss', $filters, FinancialStatements::profitAndLoss($filters));
    }

    public function balanceSheet(AccountsFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/accounts/balance-sheet', $filters, FinancialStatements::balanceSheet($filters));
    }

    public function expenses(AccountsFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/accounts/expenses', $filters, ExpenseList::for($request, $filters));
    }

    public function vat(AccountsFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/accounts/vat', $filters, VatReturnHelper::for($filters, $request->quarter()));
    }

    public function vatPrint(AccountsFilterRequest $request): Response
    {
        $filters = $request->filters();

        return Inertia::render('app/accounts/vat-print', [
            ...VatReturnHelper::for($filters, $request->quarter()),
            'business' => (string) $this->tenancy->require()->name,
            'shopName' => $this->shopName($filters),
            'filters' => $filters->toArray(),
        ]);
    }

    public function vatCsv(AccountsFilterRequest $request): StreamedResponse
    {
        $filters = $request->filters();
        $quarter = $request->quarter();
        $rows = VatReturnHelper::csv(VatReturnHelper::for($filters, $quarter), (string) $this->tenancy->require()->name, $this->shopName($filters));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            foreach ($rows as $row) {
                fputcsv($out, $row, escape: '');
            }

            fclose($out);
        }, 'vat-return-'.$quarter->key().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function fixedAssets(AccountsFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/accounts/fixed-assets', $filters, FixedAssetList::for($request, $filters));
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function page(string $component, AccountsFilters $filters, array $props): Response
    {
        $shops = Branch::query()->when($filters->shopLocked, fn ($q) => $q->whereKey($filters->shop))->orderBy('name')->get(['id', 'name']);

        return Inertia::render($component, [
            ...$props,
            'filters' => $filters->toArray(),
            'options' => ['shops' => $shops->map(fn (Branch $b) => ['value' => (string) $b->id, 'label' => (string) $b->name])->values()->all()],
        ]);
    }

    private function shopName(AccountsFilters $filters): string
    {
        return $filters->shop === null ? 'Every shop' : (CashLookup::shops([$filters->shop])[$filters->shop] ?? 'Unknown shop');
    }
}
