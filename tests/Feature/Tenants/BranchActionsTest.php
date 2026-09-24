<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Actions\DeactivateBranch;
use App\Domain\Tenancy\Actions\ReactivateBranch;
use App\Domain\Tenancy\Actions\UpdateBranch;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Enums\Nation;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

beforeEach(fn () => Mail::fake());

function branchDetails(string $code = 'BFD', string $name = 'Bradford', array $extra = []): BranchDetails
{
    return new BranchDetails(...array_merge(['code' => $code, 'name' => $name, 'nation' => Nation::England], $extra));
}

test('a branch is added with its tills and all the till fields', function () {
    $company = $this->tenant();

    $branch = app(AddBranch::class)->handle($company, branchDetails('GLA', 'Glasgow', [
        'nation' => Nation::Scotland,
        'phone' => '0141 496 0000',
        'vatNumber' => 'GB123456789',
        'licensedHoursJson' => '{"mon":["10:00","22:00"]}',
        'isDrsReturnPoint' => true,
        'areaM2' => '85.5',
    ]), 2);

    $branch->refresh();
    expect($branch->company_id)->toBe($company->id)
        ->and($branch->nation)->toBe(Nation::Scotland)
        ->and($branch->is_drs_return_point)->toBeTrue()
        ->and($branch->area_m2)->toBe('85.50')
        ->and($branch->licensed_hours_json)->toBe('{"mon":["10:00","22:00"]}')
        ->and($this->mainTillCode($branch))->toBe('01')
        ->and(Register::withoutCompanyScope()->where('branch_id', $branch->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'branch.created')->where('subject_id', $branch->id)->exists())->toBeTrue();
});

test('branch codes are 2 to 5 capital letters', function (string $code) {
    app(AddBranch::class)->handle($this->tenant(), branchDetails($code));
})->with(['A', 'ABCDEF', 'L1', 'L-S'])->throws(ValidationException::class);

test('lower-case codes are upper-cased', function () {
    $branch = app(AddBranch::class)->handle($this->tenant(), branchDetails('bfd'));

    expect($branch->code)->toBe('BFD');
});

test('branch codes are unique per company, not across companies', function () {
    $alpha = $this->tenant('Alpha', code: 'LDS');
    $bravo = $this->tenant('Bravo', code: 'LDS');

    expect($this->branchOf($bravo)->code)->toBe('LDS');
    expect(fn () => app(AddBranch::class)->handle($alpha, branchDetails('LDS')))->toThrow(ValidationException::class, 'already uses the code LDS');
});

test('a deleted branch keeps its code so receipt numbers never repeat', function () {
    $company = $this->tenant();
    $branch = app(AddBranch::class)->handle($company, branchDetails('BFD'));
    $branch->delete();

    app(AddBranch::class)->handle($company, branchDetails('BFD'));
})->throws(ValidationException::class);

test('branches cannot be added to a cancelled company', function () {
    app(AddBranch::class)->handle(Company::factory()->cancelled()->create(), branchDetails());
})->throws(ValidationException::class, 'cancelled');

test('updating a branch saves and audits the changed fields', function () {
    $company = $this->tenant();
    $branch = $this->branchOf($company);

    app(UpdateBranch::class)->handle($branch, branchDetails('LEE', 'Leeds Kirkgate', ['address' => '12 Kirkgate, Leeds']));

    $branch->refresh();
    $entry = AuditLog::query()->where('action', 'branch.updated')->firstOrFail();
    expect($branch->code)->toBe('LEE')
        ->and($branch->name)->toBe('Leeds Kirkgate')
        ->and($entry->company_id)->toBe($company->id)
        ->and(array_keys($entry->after))->toEqualCanonicalizing(['code', 'name']);
});

test('updating a branch to a code another branch uses is refused', function () {
    $company = $this->tenant();
    app(AddBranch::class)->handle($company, branchDetails('BFD'));

    app(UpdateBranch::class)->handle($this->branchOf($company), branchDetails('BFD', 'Leeds'));
})->throws(ValidationException::class);

test('a branch can be deactivated and reactivated', function () {
    $company = $this->tenant();
    $bradford = app(AddBranch::class)->handle($company, branchDetails());

    app(DeactivateBranch::class)->handle($bradford);
    expect($bradford->fresh()->is_active)->toBeFalse();

    app(ReactivateBranch::class)->handle($bradford);
    expect($bradford->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->whereIn('action', ['branch.deactivated', 'branch.reactivated'])->count())->toBe(2);
});

test('the last active branch cannot be deactivated', function () {
    app(DeactivateBranch::class)->handle($this->branchOf($this->tenant()));
})->throws(ValidationException::class, 'only active branch');

test('branch actions never touch another company', function () {
    $alpha = $this->tenant('Alpha');
    $this->tenant('Bravo');
    $alphaBranch = app(AddBranch::class)->handle($alpha, branchDetails());

    app(DeactivateBranch::class)->handle($alphaBranch);

    expect(Branch::withoutCompanyScope()->where('is_active', false)->pluck('id')->all())->toBe([$alphaBranch->id]);
});
