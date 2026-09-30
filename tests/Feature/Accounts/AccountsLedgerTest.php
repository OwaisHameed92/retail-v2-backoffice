<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Tests\Feature\Accounts\AccountsFixtures as A;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 5.5: the chart grouped by code, the trial balance, P&L and balance sheet from the journal lines, and the
 * refund entries posted before the till's 0.1.15 fix (flagged, and corrected on request).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
    [$this->company] = TillFixtures::tenant();
    A::book($this->company->id);
    A::salesData($this->company->id);
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $this->q = '?from=2026-09-01&to=2026-09-23&shop=all';
});

test('the chart of accounts has one line per code for every shop\'s copy, with its balance', function () {
    $props = C::props($this->actingAs($this->owner)->get('/app/accounts'.$this->q));
    $byCode = collect($props['accounts'])->keyBy('code');

    expect(array_column($props['accounts'], 'code'))->toBe(['1000', '2200', '2240', '4000', '5000'])
        ->and($byCode['4000'])->toMatchArray(['name' => 'Sales standard rated', 'type' => 'income', 'shops' => 2, 'vatBox' => 6, 'balance' => '175.00', 'movement' => '155.00'])
        ->and($byCode['1000'])->toMatchArray(['type' => 'asset', 'balance' => '180.00', 'movement' => '156.00'])
        ->and($byCode['2240'])->toMatchArray(['balance' => '0.00', 'hasLines' => false])
        ->and($props['shopCount'])->toBe(2);
});

test('the trial balance balances, for the period and at the end date', function () {
    $props = C::props($this->actingAs($this->owner)->get('/app/accounts/trial-balance'.$this->q));
    $rows = collect($props['rows'])->keyBy('code');

    expect($props['balanced'])->toBeTrue()
        ->and($props['totals'])->toBe(['periodDebit' => '228.00', 'periodCredit' => '228.00', 'debit' => '210.00', 'credit' => '210.00', 'difference' => '0.00'])
        ->and($rows['1000'])->toMatchArray(['debit' => '180.00', 'credit' => null])
        ->and($rows['4000'])->toMatchArray(['debit' => null, 'credit' => '175.00', 'periodDebit' => '5.00', 'periodCredit' => '160.00'])
        ->and($rows['2200']['credit'])->toBe('35.00')
        ->and($rows['5000']['debit'])->toBe('30.00');
});

test('the refund posted before the 0.1.15 fix is flagged, and corrected figures turn it round and still balance', function () {
    $asPosted = C::props($this->actingAs($this->owner)->get('/app/accounts/trial-balance'.$this->q));
    expect($asPosted['refundFix'])->toBe(['entries' => 1, 'sales' => '10.00']);

    $fixed = C::props($this->actingAs($this->owner)->get('/app/accounts/trial-balance'.$this->q.'&fix=1'));
    $rows = collect($fixed['rows'])->keyBy('code');
    expect($fixed['balanced'])->toBeTrue()
        ->and($fixed['filters']['fix'])->toBeTrue()
        ->and($fixed['totals']['debit'])->toBe('186.00')
        ->and($rows['4000']['credit'])->toBe('155.00')
        ->and($rows['2200']['credit'])->toBe('31.00')
        ->and($rows['1000']['debit'])->toBe('156.00');

    $journals = C::props($this->actingAs($this->owner)->get('/app/accounts/journals'.$this->q.'&refType=Refund'));
    expect(collect($journals['entries']['data'])->pluck('oldRefund', 'id')->all())->toBe([A::E_NEW_REFUND => false, A::E_OLD_REFUND => true]);

    $this->actingAs($this->owner)->get('/app/accounts/journals/'.A::E_OLD_REFUND)->assertInertia(fn ($page) => $page
        ->component('app/accounts/journal')
        ->where('entry.oldRefund', true)
        ->has('entry.lines', 3));
    $this->actingAs($this->owner)->get('/app/accounts/journals/'.A::E_NEW_REFUND)->assertInertia(fn ($page) => $page->where('entry.oldRefund', false));
});

test('profit and loss sets journal sales beside the till sales data, which agree once old refunds are corrected', function () {
    $pl = C::props($this->actingAs($this->owner)->get('/app/accounts/profit-and-loss'.$this->q));
    expect($pl['totals'])->toBe(['income' => '155.00', 'costOfSales' => '30.00', 'grossProfit' => '125.00', 'overheads' => '0.00', 'netProfit' => '125.00'])
        ->and($pl['salesCheck'])->toBe(['journals' => '155.00', 'salesData' => '135.00', 'difference' => '20.00', 'differs' => true]);

    $fixed = C::props($this->actingAs($this->owner)->get('/app/accounts/profit-and-loss'.$this->q.'&fix=1'));
    expect($fixed['totals']['income'])->toBe('135.00')
        ->and($fixed['salesCheck']['differs'])->toBeFalse();
});

test('the balance sheet balances: assets equal liabilities plus capital and profit to date', function () {
    $bs = C::props($this->actingAs($this->owner)->get('/app/accounts/balance-sheet'.$this->q));

    expect($bs['balanced'])->toBeTrue()
        ->and($bs['totals'])->toMatchArray(['assets' => '180.00', 'liabilities' => '35.00', 'profit' => '145.00', 'netAssets' => '145.00', 'difference' => '0.00']);
});

test('journals filter by account, type and shop', function () {
    $ids = fn (string $q) => collect(C::props($this->actingAs($this->owner)->get('/app/accounts/journals'.$q))['entries']['data'])->pluck('id')->sort()->values()->all();

    expect($ids($this->q.'&account=5000'))->toBe([A::E_PURCHASE])
        ->and($ids($this->q.'&refType=Sale'))->toEqualCanonicalizing([A::E_LEEDS, A::E_BRAD])
        ->and($ids('?from=2026-09-01&to=2026-09-23&shop='.TillFixtures::BRADFORD))->toBe([A::E_BRAD])
        ->and($ids('?from=2026-08-01&to=2026-08-31&shop=all'))->toBe([A::E_AUG]);
});
