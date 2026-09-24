<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\ActivateCompany;
use App\Domain\Tenancy\Actions\CancelCompany;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Actions\UnsuspendCompany;
use App\Domain\Tenancy\Actions\UpdateCompany;
use App\Domain\Tenancy\Data\CompanyDetails;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;

test('a trial company can be activated', function () {
    $company = Company::factory()->trial()->create();

    app(ActivateCompany::class)->handle($company);

    expect($company->fresh()->status)->toBe(CompanyStatus::Active)
        ->and($company->fresh()->activated_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'company.activated')->where('company_id', $company->id)->exists())->toBeTrue();
});

test('activating an active or suspended company is refused', function (CompanyStatus $status) {
    app(ActivateCompany::class)->handle(Company::factory()->status($status)->create());
})->with([CompanyStatus::Active, CompanyStatus::Suspended])->throws(ValidationException::class);

test('suspending needs a reason', function () {
    app(SuspendCompany::class)->handle(Company::factory()->create(), '   ');
})->throws(ValidationException::class, 'Enter a reason');

test('suspend remembers the previous status and unsuspend restores it', function () {
    $company = Company::factory()->trial()->create();

    app(SuspendCompany::class)->handle($company, 'Chargeback on card');
    $company->refresh();

    expect($company->status)->toBe(CompanyStatus::Suspended)
        ->and($company->suspended_from_status)->toBe(CompanyStatus::Trial)
        ->and($company->suspension_reason)->toBe('Chargeback on card')
        ->and($company->suspended_at)->not->toBeNull();

    app(UnsuspendCompany::class)->handle($company);
    $company->refresh();

    expect($company->status)->toBe(CompanyStatus::Trial)
        ->and($company->suspended_at)->toBeNull()
        ->and($company->suspension_reason)->toBeNull();
});

test('suspension is audited with its reason', function () {
    $company = Company::factory()->create();

    app(SuspendCompany::class)->handle($company, 'Unpaid invoice INV-0042');

    $entry = AuditLog::query()->where('action', 'company.suspended')->firstOrFail();
    expect($entry->company_id)->toBe($company->id)
        ->and($entry->meta)->toBe(['reason' => 'Unpaid invoice INV-0042'])
        ->and($entry->before['status'])->toBe('active')
        ->and($entry->after['status'])->toBe('suspended');
});

test('suspended or cancelled companies cannot be suspended again', function (CompanyStatus $status) {
    app(SuspendCompany::class)->handle(Company::factory()->status($status)->create(), 'Again');
})->with([CompanyStatus::Suspended, CompanyStatus::Cancelled])->throws(ValidationException::class);

test('unsuspending a company that is not suspended is refused', function () {
    app(UnsuspendCompany::class)->handle(Company::factory()->create());
})->throws(ValidationException::class, 'is not suspended');

test('cancelling needs a reason and clears any suspension', function () {
    $company = Company::factory()->suspended()->create();

    expect(fn () => app(CancelCompany::class)->handle($company, ''))->toThrow(ValidationException::class);

    app(CancelCompany::class)->handle($company, 'Shop closed');
    $company->refresh();

    expect($company->status)->toBe(CompanyStatus::Cancelled)
        ->and($company->cancellation_reason)->toBe('Shop closed')
        ->and($company->cancelled_at)->not->toBeNull()
        ->and($company->suspended_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'company.cancelled')->exists())->toBeTrue();
});

test('a cancelled company cannot be cancelled twice but can be reinstated', function () {
    $company = Company::factory()->cancelled()->create();

    expect(fn () => app(CancelCompany::class)->handle($company, 'Again'))->toThrow(ValidationException::class);

    app(ActivateCompany::class)->handle($company);
    $company->refresh();

    expect($company->status)->toBe(CompanyStatus::Active)
        ->and($company->cancelled_at)->toBeNull()
        ->and($company->cancellation_reason)->toBeNull();
});

test('updating details records only what changed', function () {
    $company = Company::factory()->create(['name' => 'Old Name', 'phone' => '0113 000 0000']);

    app(UpdateCompany::class)->handle($company, new CompanyDetails(
        name: 'New Name',
        legalName: $company->legal_name,
        vatNumber: $company->vat_number,
        companyNumber: $company->company_number,
        address: $company->address,
        phone: '0113 000 0000',
        email: $company->email,
    ));

    $entry = AuditLog::query()->where('action', 'company.updated')->firstOrFail();
    expect($company->fresh()->name)->toBe('New Name')
        ->and($entry->before)->toBe(['name' => 'Old Name'])
        ->and($entry->after)->toBe(['name' => 'New Name']);
});

test('saving unchanged details writes no audit entry', function () {
    $company = Company::factory()->create(['contact_name' => null, 'notes' => null]);

    app(UpdateCompany::class)->handle($company, new CompanyDetails(
        name: $company->name,
        legalName: $company->legal_name,
        vatNumber: $company->vat_number,
        companyNumber: $company->company_number,
        address: $company->address,
        phone: $company->phone,
        email: $company->email,
    ));

    expect(AuditLog::query()->where('action', 'company.updated')->exists())->toBeFalse();
});
