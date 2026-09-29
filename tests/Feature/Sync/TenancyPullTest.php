<?php

use App\Domain\Tenancy\Actions\UpdateCompany;
use App\Domain\Tenancy\Data\CompanyDetails;
use App\Domain\Tenancy\Models\Company;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\JsonSchemaSubset;

/** Module 2.9B: portal edits of the till's Company and Branch rows in the pull (contract v1.4.1 §6.1, §18.3). */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-09-29 10:00:00');
    $this->entityErrors = fn (array $payload, string $entity) => (new JsonSchemaSubset(base_path(TillFixtures::CONTRACT.'/schemas')))
        ->validate(json_decode((string) json_encode($payload)), "entities/{$entity}.schema.json");
});

test('nothing is sent for companies and branches the portal has not edited since', function () {
    expect(Pull::changes($this->sync->pull(0)))->toBe([])
        ->and(Pull::changes($this->sync->pull(0, bradford: true)))->toBe([]);
});

test('a portal edit of the business\'s details reaches every till as its own Company row', function () {
    $this->travel(1)->minutes();
    app(UpdateCompany::class)->handle($this->company, new CompanyDetails(name: 'Kirkgate Stores Ltd', vatNumber: 'GB123456789', phone: '0113 496 0000'));

    foreach ([false, true] as $bradford) {
        $reply = $this->sync->pull(0, bradford: $bradford)->assertOk();
        expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([]);
        $change = Pull::changes($reply)[0];

        expect($change)->toMatchArray([
            'entity' => 'Company', 'entityId' => TillFixtures::COMPANY, 'op' => 'U', 'version' => 1,
            'companyId' => TillFixtures::COMPANY, 'branchId' => '',
        ])->and($change['payload'])->toMatchArray([
            'name' => 'Kirkgate Stores Ltd', 'vatNumber' => 'GB123456789', 'phone' => '0113 496 0000',
            'id' => TillFixtures::COMPANY, 'companyId' => TillFixtures::COMPANY, 'updatedAt' => '2026-09-29T10:01:00Z',
        ])->and(($this->entityErrors)($change['payload'], 'Company'))->toBe([]);
    }
});

test('a portal edit of a shop reaches that shop\'s till only; the till\'s own push and its counters are never sent back', function () {
    $this->travel(1)->minutes();
    $this->sync->bradford->forceFill(['name' => 'Bradford Market Street', 'address' => '1 Market Street'])->save();

    $bradford = Pull::changes($this->sync->pull(0, bradford: true));
    expect(Pull::changes($this->sync->pull(0)))->toBe([])
        ->and($bradford[0])->toMatchArray(['entity' => 'Branch', 'entityId' => TillFixtures::BRADFORD, 'branchId' => TillFixtures::BRADFORD])
        ->and($bradford[0]['payload'])->toMatchArray(['code' => 'BRD', 'name' => 'Bradford Market Street', 'address' => '1 Market Street', 'isActive' => true])
        ->and(($this->entityErrors)($bradford[0]['payload'], 'Branch'))->toBe([]);

    // The till pushes its own Branch row (a new PO counter, a new phone): stored, never echoed.
    $row = [...$bradford[0]['payload'], 'phone' => '01274 000000', 'nextPoNo' => 42, 'rowVersion' => 7, 'updatedAt' => '2026-09-29T10:05:00Z'];
    $this->sync->push([TillFixtures::envelope('Branch', $row, 1, ['branchId' => TillFixtures::BRADFORD, 'op' => 'U'])], bradford: true)->assertOk();
    expect(Pull::changes($this->sync->pull(1, bradford: true)))->toBe([])
        ->and($this->sync->bradford->fresh()->phone)->toBe('01274 000000');

    // Portal changes that are not the till's members (limits, licence settings) send nothing either.
    $this->sync->bradford->forceFill(['max_registers' => 3])->save();
    $this->company->forceFill(['max_branches' => 5])->save();
    expect(Pull::changes($this->sync->pull(1, bradford: true)))->toBe([])
        ->and(Pull::changes($this->sync->pull(0)))->toBe([]);
});

test('tenant isolation: another business\'s edits never reach this company\'s tills', function () {
    $other = Company::factory()->create();
    app(UpdateCompany::class)->handle($other, new CompanyDetails(name: 'Someone Else'));

    expect(Pull::changes($this->sync->pull(0)))->toBe([]);
});
