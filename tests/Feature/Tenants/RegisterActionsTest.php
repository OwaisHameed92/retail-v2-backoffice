<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Actions\AddRegister;
use App\Domain\Tenancy\Actions\DeactivateBranch;
use App\Domain\Tenancy\Actions\DeactivateRegister;
use App\Domain\Tenancy\Actions\ReactivateRegister;
use App\Domain\Tenancy\Actions\SetMainTill;
use App\Domain\Tenancy\Actions\UpdateRegister;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

beforeEach(fn () => Mail::fake());

function emptyBranch(): Branch
{
    // Module 1.11: tills allowed high enough for the code tests.
    return Branch::factory()->forCompany(Company::factory()->create())->create(['code' => 'LDS', 'max_registers' => 999]);
}

test('the first till of a branch becomes its main till', function () {
    $branch = emptyBranch();

    $first = app(AddRegister::class)->handle($branch);
    $second = app(AddRegister::class)->handle($branch);

    expect($first->code)->toBe('01')->and($first->name)->toBe('Till 1')->and($first->is_main_till)->toBeTrue()
        ->and($second->code)->toBe('02')->and($second->is_main_till)->toBeFalse();
    $this->assertMainTillInvariant($branch);
});

test('a new till can take over as main till', function () {
    $branch = emptyBranch();
    app(AddRegister::class)->handle($branch);

    $new = app(AddRegister::class)->handle($branch, 'Front counter', '05', makeMain: true);

    expect($new->is_main_till)->toBeTrue()->and($this->mainTillCode($branch))->toBe('05');
    $this->assertMainTillInvariant($branch);
});

test('till codes are two digits, unique per branch and never reused', function () {
    $branch = emptyBranch();
    app(AddRegister::class)->handle($branch, code: '07');

    expect(fn () => app(AddRegister::class)->handle($branch, code: '07'))->toThrow(ValidationException::class, 'already exists')
        ->and(fn () => app(AddRegister::class)->handle($branch, code: '7'))->toThrow(ValidationException::class)
        ->and(fn () => app(AddRegister::class)->handle($branch, code: '00'))->toThrow(ValidationException::class);

    $this->registerOf($branch, '07')->delete();
    expect(fn () => app(AddRegister::class)->handle($branch, code: '07'))->toThrow(ValidationException::class);

    // The same code in another branch is fine.
    expect(app(AddRegister::class)->handle(emptyBranch(), code: '07')->code)->toBe('07');
});

test('the next free code fills gaps and stops at 99', function () {
    $branch = emptyBranch();
    app(AddRegister::class)->handle($branch, code: '02');
    expect(app(AddRegister::class)->handle($branch)->code)->toBe('01');

    for ($n = 3; $n <= 99; $n++) {
        Register::factory()->forBranch($branch)->create(['code' => Register::codeFor($n)]);
    }

    app(AddRegister::class)->handle($branch);
})->throws(ValidationException::class, 'maximum of 99');

test('tills cannot be added to an inactive branch', function () {
    $company = $this->tenant();
    $branch = Branch::factory()->forCompany($company)->inactive()->create(['code' => 'OLD']);

    app(AddRegister::class)->handle($branch);
})->throws(ValidationException::class, 'inactive');

test('setting the main till moves the flag', function () {
    $company = $this->tenant(tills: 3);
    $branch = $this->branchOf($company);

    app(SetMainTill::class)->handle($this->registerOf($branch, '03'));

    expect($this->mainTillCode($branch))->toBe('03');
    $this->assertMainTillInvariant($branch);
    $entry = AuditLog::query()->where('action', 'register.main_till_changed')->firstOrFail();
    expect($entry->before['main_register_id'])->toBe($this->registerOf($branch, '01')->id);
});

test('an inactive till cannot be the main till', function () {
    $branch = $this->branchOf($this->tenant(tills: 2));
    app(DeactivateRegister::class)->handle($this->registerOf($branch, '02'));

    app(SetMainTill::class)->handle($this->registerOf($branch, '02'));
})->throws(ValidationException::class, 'inactive');

test('deactivating the main till promotes the next active till', function () {
    $branch = $this->branchOf($this->tenant(tills: 3));

    app(DeactivateRegister::class)->handle($this->registerOf($branch, '01'));

    expect($this->mainTillCode($branch))->toBe('02')
        ->and($this->registerOf($branch, '01')->is_active)->toBeFalse();
    $this->assertMainTillInvariant($branch);
    expect(AuditLog::query()->where('action', 'register.deactivated')->firstOrFail()->meta)
        ->toBe(['new_main_register_id' => $this->registerOf($branch, '02')->id]);
});

test('deactivating the only till leaves no main till, reactivating restores it', function () {
    $branch = $this->branchOf($this->tenant(tills: 1));
    $till = $this->registerOf($branch, '01');

    app(DeactivateRegister::class)->handle($till);
    expect($this->mainTillCode($branch))->toBeNull();
    $this->assertMainTillInvariant($branch);

    app(ReactivateRegister::class)->handle($till);
    expect($this->mainTillCode($branch))->toBe('01');
    $this->assertMainTillInvariant($branch);
});

test('a reactivated till does not steal the main flag', function () {
    $branch = $this->branchOf($this->tenant(tills: 2));
    app(DeactivateRegister::class)->handle($this->registerOf($branch, '02'));

    app(ReactivateRegister::class)->handle($this->registerOf($branch, '02'));

    expect($this->mainTillCode($branch))->toBe('01');
});

test('tills of an inactive branch cannot be reactivated', function () {
    $company = $this->tenant(tills: 2);
    $leeds = $this->branchOf($company);
    app(AddBranch::class)->handle($company, new BranchDetails('BFD', 'Bradford'));
    app(DeactivateRegister::class)->handle($this->registerOf($leeds, '02'));
    app(DeactivateBranch::class)->handle($leeds);

    app(ReactivateRegister::class)->handle($this->registerOf($leeds, '02'));
})->throws(ValidationException::class, 'Reactivate the branch first');

test('a till can be renamed and re-coded', function () {
    $branch = $this->branchOf($this->tenant(tills: 2));

    app(UpdateRegister::class)->handle($this->registerOf($branch, '02'), 'Kiosk', '09');

    $till = $this->registerOf($branch, '09');
    expect($till->name)->toBe('Kiosk')
        ->and(AuditLog::query()->where('action', 'register.updated')->firstOrFail()->after)->toBe(['code' => '09', 'name' => 'Kiosk']);
    expect(fn () => app(UpdateRegister::class)->handle($till, 'Kiosk', '01'))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateRegister::class)->handle($till, ' ', '09'))->toThrow(ValidationException::class);
});

test('register rows carry the branch company id', function () {
    $company = $this->tenant(tills: 1);

    expect(Register::withoutCompanyScope()->pluck('company_id')->unique()->all())->toBe([$company->id]);
});
