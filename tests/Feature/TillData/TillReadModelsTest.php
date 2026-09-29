<?php

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Sale;
use App\Domain\TillData\Models\SaleLine;
use App\Domain\TillData\Models\StockMovement;
use App\Domain\TillData\Queries\TillSum;
use Carbon\CarbonImmutable;
use Tests\Feature\TillData\TillFixtures;

beforeEach(function () {
    [$this->company, $this->leeds, $this->bradford] = TillFixtures::tenant();
    TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.json'));
    TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.second-till.json'));
    TillFixtures::apply($this->company, $this->bradford, TillFixtures::sample('push-request.second-branch.json'));
    $this->as = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
});

it('filters sales by status, trading type, branch, till and completion time', function () {
    ($this->as)(function () {
        // Leeds: one sale on till 1, two on till 2 (v1.4.1 second-till sample); Bradford: one.
        expect(Sale::query()->completed()->count())->toBe(4)
            ->and(Sale::query()->trading()->forBranch($this->leeds)->count())->toBe(3)
            ->and(Sale::query()->trading()->forBranch(null)->count())->toBe(4)
            ->and(Sale::query()->forRegister(TillFixtures::TILL_2)->count())->toBe(2)
            ->and(Sale::query()->completedBetween(CarbonImmutable::parse('2026-09-23 00:00', 'Europe/London'), CarbonImmutable::parse('2026-09-24 00:00', 'Europe/London'))->count())->toBe(4)
            ->and(Sale::query()->completedBetween(CarbonImmutable::parse('2026-09-24'), CarbonImmutable::parse('2026-09-25'))->count())->toBe(0);
    });
});

it('sums money exactly in the database', function () {
    ($this->as)(function () {
        $totals = Sale::totals(Sale::query()->trading()->forBranch($this->leeds));

        expect($totals['count'])->toBe(3)
            ->and($totals['total'])->toBe(bcadd('5.15', TillSum::of(Sale::query()->forRegister(TillFixtures::TILL_2), 'total'), 2))
            ->and($totals['net_total'])->toBe(bcsub($totals['total'], $totals['vat_total'], 2))
            ->and(TillSum::of(Sale::query()->whereRaw('1 = 0'), 'total'))->toBe('0.00');

        $lines = SaleLine::totals(SaleLine::query()->forBranch($this->leeds)->forProduct('01K5T0Q8C4000000000000P002'));
        expect($lines['qty'])->toMatch('/^\d+\.0000$/')
            ->and($lines['cost'])->toMatch('/^\d+\.\d{4}$/');

        expect(StockMovement::netQty(StockMovement::query()->forBranch($this->leeds)->forProduct('01K5T0Q8C4000000000000P001')))->toMatch('/^-\d+\.0000$/');
    });
});

it('does not drift like floats: a thousand 0.10 sales sum to exactly 100.00', function () {
    $sale = TillFixtures::sample('entities/Sale.json');
    $changes = [];

    for ($i = 1; $i <= 1000; $i++) {
        $id = '01K5VC'.str_pad((string) $i, 20, '0', STR_PAD_LEFT);
        $changes[] = TillFixtures::envelope('Sale', [...$sale, 'id' => $id, 'total' => 0.1, 'vatTotal' => 0.01], $i);
    }

    TillFixtures::apply($this->company, $this->leeds, $changes);

    ($this->as)(function () {
        $query = Sale::query()->where('id', 'like', '01K5VC%');

        expect(TillSum::many($query, ['total' => 2, 'vat_total' => 2]))->toBe(['total' => '100.00', 'vat_total' => '10.00']);
    });
});
