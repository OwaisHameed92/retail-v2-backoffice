<?php

use App\Domain\Reporting\Actions\CheckReports;
use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\DirtyDays;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reporting\ReportFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 3.1: the rpt_* tables never double-count — replays, duplicates, out-of-order rows, edits of a completed
 * sale and late refunds — and the incrementally kept rows always equal a full rebuild (DASHBOARD.md §4.8).
 */

beforeEach(function () {
    [$this->company, $this->leeds, $this->bradford] = TillFixtures::tenant();
});

function expectIncrementalEqualsRebuild(): void
{
    $incremental = ReportFixtures::snapshot();
    expect(app(CheckReports::class)->handle()['mismatches'])->toBe([]);

    test()->artisan('reports:rebuild')->assertSuccessful();
    expect(ReportFixtures::snapshot())->toBe($incremental);
}

it('changes nothing when the same batch is pushed again', function () {
    $batch = TillFixtures::sample('push-request.json');
    TillFixtures::apply($this->company, $this->leeds, $batch);
    $first = ReportFixtures::snapshot();

    TillFixtures::apply($this->company, $this->leeds, $batch);

    expect(ReportFixtures::snapshot())->toBe($first)
        ->and(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23')['net'])->toBe('4.53')
        ->and(DB::table(ReportTables::DIRTY_DAYS)->count())->toBe(0);
    expectIncrementalEqualsRebuild();
});

it('counts a sale once whose lines, VAT and payment arrive before or after it, in separate pushes', function () {
    $basket = ReportFixtures::basket('000301', '2026-09-23T12:00:00Z', 300);
    $sale = array_values(array_filter($basket, fn ($r) => $r['entity'] === 'Sale'));
    $children = array_values(array_filter($basket, fn ($r) => $r['entity'] !== 'Sale'));

    // The two lines first (a push of their own), then the sale, then the payment and VAT rows, then all again.
    ReportFixtures::push($this->company, $this->leeds, array_slice($children, 0, 2));
    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toBeNull();

    ReportFixtures::push($this->company, $this->leeds, $sale);
    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toMatchArray(['txn_count' => '1', 'net' => '0.00', 'takings' => '5.15', 'cost' => '1.7600']);

    ReportFixtures::push($this->company, $this->leeds, array_slice($children, 2));
    ReportFixtures::push($this->company, $this->leeds, $children);

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toMatchArray([
        'txn_count' => '1', 'gross' => '5.15', 'net' => '4.53', 'takings' => '5.15', 'cost' => '1.7600',
    ])
        ->and(DB::table(ReportTables::TENDER_DAILY)->sum('count'))->toBe(1);
    expectIncrementalEqualsRebuild();
});

it('counts a sale pushed open and then completed exactly once, on its completion day', function () {
    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('000401', '2026-09-23T15:00:00Z', 400, ['status' => 'open']));
    expect(DB::table(ReportTables::SALES_DAILY)->count())->toBe(0);

    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('000401', '2026-09-23T15:00:00Z', 410, ['version' => 2]));
    // An older version arriving late changes nothing.
    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('000401', '2026-09-23T15:00:00Z', 420, ['status' => 'open']));

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toMatchArray(['txn_count' => '1', 'net' => '4.53']);
    expectIncrementalEqualsRebuild();
});

it('never double-counts an edit of a completed sale; a later soft delete takes it out', function () {
    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('000501', '2026-09-23T16:00:00Z', 500));

    // The till tries to change a completed sale (immutable: kept out, recorded as a conflict).
    $edit = ReportFixtures::basket('000501', '2026-09-23T16:00:00Z', 510, ['version' => 2]);
    $edit[0]['payload']['total'] = 99.99;
    ReportFixtures::push($this->company, $this->leeds, $edit);

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toMatchArray(['txn_count' => '1', 'takings' => '5.15', 'net' => '4.53']);

    $delete = ReportFixtures::basket('000501', '2026-09-23T16:00:00Z', 520, ['version' => 3]);
    $delete[0]['op'] = 'D';
    $delete[0]['payload']['deletedAt'] = '2026-09-23T17:00:00Z';
    ReportFixtures::push($this->company, $this->leeds, [$delete[0]]);

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toBeNull();
    expectIncrementalEqualsRebuild();
});

it('lands a late refund on its own day and leaves the original day alone, however late it arrives', function () {
    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('000601', '2026-09-20T12:00:00Z', 600));
    $day20 = ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-20');

    // The till was offline: the refund made on the 22nd reaches the portal after a sale of the 24th.
    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('000603', '2026-09-24T09:00:00Z', 620));
    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('000602', '2026-09-22T12:00:00Z', 610, ['type' => 'refund', 'original' => ReportFixtures::saleId('000601')]));
    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('000602', '2026-09-22T12:00:00Z', 610, ['type' => 'refund']));

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-20'))->toBe($day20)
        ->and(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-22'))->toMatchArray(['refund_count' => '1', 'refund_gross' => '3.70', 'net' => '-3.08', 'txn_count' => '0'])
        ->and(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-24'))->toMatchArray(['txn_count' => '1', 'net' => '4.53']);
    expectIncrementalEqualsRebuild();
});

it('keeps incremental equal to a rebuild across a mixed history of both shops and tills', function () {
    TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.second-till.json'));
    TillFixtures::apply($this->company, $this->bradford, TillFixtures::sample('push-request.second-branch.json'));
    TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.json'));

    $seq = 1000;
    foreach (['2026-03-29T00:30:00Z', '2026-03-29T01:30:00Z', '2026-10-25T00:30:00Z', '2026-10-25T01:30:00Z', '2026-12-01T23:30:00Z'] as $i => $at) {
        ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('7000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $at, $seq, ['register' => $i % 2 ? TillFixtures::TILL_2 : TillFixtures::TILL_1, 'user' => $i % 2 ? ReportFixtures::USER_2 : ReportFixtures::USER_1]));
        $seq += 10;
    }

    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('700099', '2026-10-25T01:40:00Z', $seq, ['type' => 'refund']));

    expect(DB::table(ReportTables::DIRTY_DAYS)->count())->toBe(0);
    expectIncrementalEqualsRebuild();
});

it('rebuilds a day that is marked again while its rebuild runs (tokens)', function () {
    TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.json'));
    DB::table(ReportTables::DIRTY_DAYS)->insert(['company_id' => TillFixtures::COMPANY, 'branch_id' => TillFixtures::LEEDS, 'trading_day' => '2026-09-23', 'token' => 'OLD', 'marked_at' => '2026-09-23 10:00:00']);

    DirtyDays::clear(TillFixtures::COMPANY, ['OTHER']);
    expect(DB::table(ReportTables::DIRTY_DAYS)->count())->toBe(1);

    DirtyDays::clear(TillFixtures::COMPANY, ['OLD']);
    expect(DB::table(ReportTables::DIRTY_DAYS)->count())->toBe(0);
});
