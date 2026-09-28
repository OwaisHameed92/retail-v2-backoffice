<?php

use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Enums\IdMapAction;
use App\Domain\Sync\Models\IdMapping;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\Models\Sale;
use App\Domain\TillData\Models\StockMovement;
use Tests\Feature\TillData\TillFixtures;

/**
 * Module 2.1: the till keeps its own ids. A portal tenant with OUR ids receives the contract samples (the till's
 * ids C001/B001/R001) and the rows land under our company, branch and tills through id_map.
 */
function mapTillIds(Company $company, Branch $branch, Register $till1, Register $till2): void
{
    foreach ([
        [IdKind::Company, TillFixtures::COMPANY, $company->id],
        [IdKind::Branch, TillFixtures::LEEDS, $branch->id],
        [IdKind::Register, TillFixtures::TILL_1, $till1->id],
        [IdKind::Register, TillFixtures::TILL_2, $till2->id],
    ] as [$kind, $tillId, $portalId]) {
        IdMapping::withoutCompanyScope()->create([
            'kind' => $kind, 'till_id' => $tillId, 'portal_id' => $portalId, 'company_id' => $company->id,
            'branch_id' => $branch->id, 'action' => IdMapAction::Adopted,
        ]);
    }
}

beforeEach(function () {
    $this->company = Company::factory()->create(['name' => 'Kirkgate Stores']);
    $this->leeds = Branch::factory()->forCompany($this->company)->create(['code' => 'LDS', 'name' => 'Leeds']);
    $this->till1 = Register::factory()->forBranch($this->leeds)->create(['code' => '01', 'is_main_till' => true]);
    $this->till2 = Register::factory()->forBranch($this->leeds)->create(['code' => '02']);
});

it('stores a till\'s rows under our company, branch and tills when its ids differ from ours', function () {
    expect($this->company->id)->not->toBe(TillFixtures::COMPANY);
    mapTillIds($this->company, $this->leeds, $this->till1, $this->till2);
    $changes = TillFixtures::sample('push-request.json');

    $result = TillFixtures::apply($this->company, $this->leeds, $changes);

    expect($result->rejected)->toBe([])
        ->and($result->accepted)->toBe(count($changes))
        ->and($result->acknowledgedSeq)->toBe(max(array_column($changes, 'seq')));

    $sale = Sale::withoutCompanyScope()->sole();
    expect($sale->company_id)->toBe($this->company->id)
        ->and($sale->branch_id)->toBe($this->leeds->id)
        ->and($sale->register_id)->toBe($this->till1->id)
        ->and(StockMovement::withoutCompanyScope()->pluck('register_id')->unique()->values()->all())->toBe([$this->till1->id]);

    // Applying the same batch again changes nothing.
    expect(TillFixtures::apply($this->company, $this->leeds, $changes)->accepted)->toBe(count($changes))
        ->and(Sale::withoutCompanyScope()->count())->toBe(1);
});

it('refuses the till\'s ids when they are not mapped to the pushing company', function () {
    $result = TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.json'));

    expect($result->accepted)->toBe(0)
        ->and($result->firstRejection()?->code)->toBe('sync.wrong_company')
        ->and(Sale::withoutCompanyScope()->count())->toBe(0);
});

it('never translates with another company\'s map: company B cannot push rows as company A', function () {
    mapTillIds($this->company, $this->leeds, $this->till1, $this->till2);
    $companyB = Company::factory()->create(['name' => 'Bravo Mart']);
    $bradford = Branch::factory()->forCompany($companyB)->create(['code' => 'BRV']);
    Register::factory()->forBranch($bradford)->create(['code' => '01', 'is_main_till' => true]);

    // Company B's connection sends rows carrying company A's till ids.
    $result = TillFixtures::apply($companyB, $bradford, TillFixtures::sample('push-request.json'));

    expect($result->accepted)->toBe(0)
        ->and($result->firstRejection()?->code)->toBe('sync.wrong_company')
        ->and(Sale::withoutCompanyScope()->count())->toBe(0);
});
