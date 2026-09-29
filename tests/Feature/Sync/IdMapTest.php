<?php

use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Sync\Actions\RecordTillIds;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Models\IdMapping;
use App\Domain\Sync\Support\IdTranslator;
use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Support\ContractReplyGuard;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

/** Module 2.1: the till keeps its own ids; licence/activate records them in id_map (adopt / alias). */
const BFD_KEY = 'SSP-ZR0H-C1DY-EDC5-V9X6';

const BFD_INSTALL = '01K5T0Q8C4000000000000J003';

const BFD_CODE = 'CR5T-8NWE';

const BFD_TILL_COMPANY = '01K5T0Q8C4000000000000C003';

const BFD_TILL_BRANCH = '01K5T0Q8C4000000000000B003';

const BFD_TILL_REGISTER = '01K5T0Q8C4000000000000R003';

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
});

/** @return array<string, string> kind:till id => portal id */
function idMapOf(Company $company): array
{
    return IdMapping::withoutCompanyScope()->where('company_id', $company->id)->get()
        ->mapWithKeys(fn (IdMapping $m) => ["{$m->kind->value}:{$m->till_id}" => "{$m->portal_id}|{$m->action->value}"])->all();
}

/** A second branch (Bradford) with one till whose key is BFD_KEY. */
function addBradford(object $test, Company $company): array
{
    $company->forceFill(['multi_branch' => true, 'max_branches' => 3])->save();
    $bradford = app(AddBranch::class)->handle($company, new BranchDetails('BFD', 'Bradford'), 1);
    $licence = $test->giveKey($test->licenceOf($test->registerOf($bradford, '01')), BFD_KEY);

    return [$bradford, $licence];
}

function activateBradford(object $test, string $tillCompany = BFD_TILL_COMPANY, string $tillBranch = BFD_TILL_BRANCH)
{
    $body = $test->activateBody(BFD_KEY, BFD_INSTALL, BFD_CODE);
    $body['existingIds'] = ['companyId' => $tillCompany, 'branchId' => $tillBranch, 'registerId' => BFD_TILL_REGISTER];

    return $test->till('licence/activate', $body, $test->tillHeaders(BFD_INSTALL));
}

test('the first branch\'s main till is adopted: its company, branch and register ids map to ours', function () {
    [$company, $licence] = $this->keyedTenant();

    $this->activateTill()->assertOk()->assertJsonPath('licence.companyId', $company->id);

    expect(idMapOf($company))->toEqual([
        'company:'.self::TILL_COMPANY => $company->id.'|adopted',
        'branch:'.self::TILL_BRANCH => $licence->branch_id.'|adopted',
        'register:'.self::TILL_REGISTER => $licence->register_id.'|adopted',
    ]);

    // A retry records nothing new.
    $this->activateTill()->assertOk();
    expect(IdMapping::withoutCompanyScope()->count())->toBe(3);
});

test('a second branch\'s main till with its own company id is aliased to our company', function () {
    [$company] = $this->keyedTenant();
    $this->activateTill()->assertOk();
    [$bradford, $licence] = addBradford($this, $company);

    activateBradford($this)->assertOk()
        ->assertJsonPath('licence.companyId', $company->id)
        ->assertJsonPath('licence.branchId', $bradford->id);

    $alias = IdMapping::withoutCompanyScope()->where('till_id', BFD_TILL_COMPANY)->sole();
    expect($alias->action->value)->toBe('aliased')
        ->and($alias->portal_id)->toBe($company->id)
        ->and($alias->branch_id)->toBe($bradford->id)
        ->and(idMapOf($company)['branch:'.BFD_TILL_BRANCH])->toBe($bradford->id.'|adopted')
        ->and(idMapOf($company)['register:'.BFD_TILL_REGISTER])->toBe($licence->register_id.'|adopted');

    // Out: each branch gets back the company id its till knows.
    $ids = IdTranslator::forCompany($company->id);
    expect($ids->toTill(IdKind::Company, $company->id, $bradford->id))->toBe(BFD_TILL_COMPANY)
        ->and($ids->toTill(IdKind::Company, $company->id, $this->branchOf($company)->id))->toBe(self::TILL_COMPANY)
        ->and($ids->toTill(IdKind::Branch, $bradford->id))->toBe(BFD_TILL_BRANCH)
        ->and($ids->toPortal(IdKind::Register, BFD_TILL_REGISTER))->toBe($licence->register_id);
});

test('a second till of the branch maps its own register id to the register its key is bound to', function () {
    [$company, $first] = $this->keyedTenant();
    $this->activateTill()->assertOk();
    $second = $this->giveKey($this->licenceOf($this->registerOf($this->branchOf($company), '02')), self::OTHER_KEY);

    $body = $this->activateBody(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE);
    $body['existingIds'] = ['companyId' => self::TILL_COMPANY, 'branchId' => self::TILL_BRANCH, 'registerId' => self::OTHER_TILL_REGISTER];
    $this->till('licence/activate', $body, $this->tillHeaders(self::OTHER_INSTALL))->assertOk();

    expect(idMapOf($company))->toHaveCount(4)
        ->and(idMapOf($company)['register:'.self::OTHER_TILL_REGISTER])->toBe($second->register_id.'|adopted')
        ->and(idMapOf($company)['register:'.self::TILL_REGISTER])->toBe($first->register_id.'|adopted');
});

test('till ids already mapped to another business are refused with a clear error and an admin alert', function () {
    [$companyA] = $this->keyedTenant();
    $this->activateTill()->assertOk();
    [$companyB, $licenceB] = $this->keyedTenant('Corner Shop', 1, 'CRN', self::OTHER_KEY);

    // Company B's key typed on a PC that holds company A's data.
    $body = $this->activateBody(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE);
    $body['existingIds'] = ['companyId' => self::TILL_COMPANY, 'branchId' => self::TILL_BRANCH, 'registerId' => self::TILL_REGISTER];

    $this->till('licence/activate', $body, $this->tillHeaders(self::OTHER_INSTALL))
        ->assertStatus(409)
        ->assertJsonPath('code', 'licence.ids_conflict')
        ->assertJsonPath('details.kind', 'company')
        ->assertJsonPath('message', RecordTillIds::ANOTHER_BUSINESS)
        ->assertJsonMissingPath('licenceToken');

    $alert = LicenceAlert::withoutCompanyScope()->where('licence_id', $licenceB->id)->sole();
    expect($alert->type)->toBe(LicenceAlertType::TillIdsConflict)
        ->and($alert->company_id)->toBe($companyB->id)
        ->and($licenceB->fresh()->device_id)->toBeNull()
        ->and(idMapOf($companyB))->toBe([])
        ->and(idMapOf($companyA))->toHaveCount(3);
});

test('a PC holding one branch\'s data cannot take a key of another branch of the same business', function () {
    [$company] = $this->keyedTenant();
    $this->activateTill()->assertOk();
    [, $licence] = addBradford($this, $company);

    activateBradford($this, BFD_TILL_COMPANY, self::TILL_BRANCH)
        ->assertStatus(409)
        ->assertJsonPath('code', 'licence.ids_conflict')
        ->assertJsonPath('details.kind', 'branch')
        ->assertJsonPath('message', RecordTillIds::ANOTHER_BRANCH);

    expect($licence->fresh()->device_id)->toBeNull()
        ->and(IdMapping::withoutCompanyScope()->where('till_id', BFD_TILL_COMPANY)->exists())->toBeFalse();
});

test('the translator keeps a refused tenancy row\'s own key and never uses another company\'s map', function () {
    [$company, $licence] = $this->keyedTenant();
    $this->activateTill()->assertOk();

    $raw = ['seq' => 1, 'entity' => 'Register', 'entityId' => self::TILL_REGISTER, 'op' => 'U', 'version' => 4, 'companyId' => self::TILL_COMPANY,
        'branchId' => self::TILL_BRANCH, 'registerId' => '', 'at' => '2026-10-05T09:00:00Z',
        'payload' => ['id' => self::TILL_REGISTER, 'companyId' => self::TILL_COMPANY, 'branchId' => self::TILL_BRANCH, 'toBranchId' => self::TILL_BRANCH, 'name' => 'Till 1']];

    $out = IdTranslator::forCompany($company->id)->change($raw);
    expect($out['entityId'])->toBe($licence->register_id)
        ->and($out['key'])->toBe('Register:'.self::TILL_REGISTER.':4')
        ->and($out['companyId'])->toBe($company->id)
        ->and($out['payload']['branchId'])->toBe($licence->branch_id)
        ->and($out['payload']['toBranchId'])->toBe($licence->branch_id)
        ->and($out['payload']['name'])->toBe('Till 1');

    $other = Company::factory()->create();
    expect(IdTranslator::forCompany($other->id)->change($raw))->toBe($raw);
});

test('licence.ids_conflict is official (contract v1.4.1 answers b): 409 in error-codes.json for activate, en-GB owner messages of at most 500 characters', function () {
    $codes = collect(json_decode((string) file_get_contents(base_path('docs/contracts/portal-api-v1.4.1/docs/web-portal-api/licensing/samples/error-codes.json')), true));
    $entry = $codes->firstWhere('code', 'licence.ids_conflict');

    expect($entry['status'])->toBe(409)
        ->and($entry['endpoints'])->toContain('licence/activate')
        ->and(ContractReplyGuard::PENDING_CODES)->not->toHaveKey('licence.ids_conflict');

    foreach ([RecordTillIds::ANOTHER_BUSINESS, RecordTillIds::ANOTHER_BRANCH] as $message) {
        expect(mb_strlen($message))->toBeLessThanOrEqual(500)
            ->and($message)->toContain('Nothing has been changed', 'dealer')
            ->not->toMatch('/[0-9A-HJKMNP-TV-Z]{26}/')
            ->not->toContain('licence key cannot be used on it');
    }
});
