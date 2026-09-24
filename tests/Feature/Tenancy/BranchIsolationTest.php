<?php

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Exceptions\CompanyMismatch;
use App\Domain\Tenancy\Exceptions\MissingCurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->alpha = $this->tenant('Alpha Stores', tills: 2, code: 'ALP');
    $this->bravo = $this->tenant('Bravo Mart', tills: 1, code: 'BRV');
});

test('company A only sees its own branches and tills', function () {
    app(CurrentCompany::class)->runAs($this->alpha, function () {
        expect(Branch::query()->pluck('code')->all())->toBe(['ALP'])
            ->and(Register::query()->count())->toBe(2)
            ->and(Branch::query()->find($this->branchOf($this->bravo, 'BRV')->id))->toBeNull()
            ->and(Register::query()->find($this->registerOf($this->branchOf($this->bravo, 'BRV'), '01')->id))->toBeNull();
    });
});

test('admin code sees every company through the escape hatch', function () {
    expect(Branch::withoutCompanyScope()->count())->toBe(2)
        ->and(Register::withoutCompanyScope()->count())->toBe(3);
});

test('branches and tills cannot be queried without a current company', function () {
    expect(fn () => Branch::query()->get())->toThrow(MissingCurrentCompany::class)
        ->and(fn () => Register::query()->get())->toThrow(MissingCurrentCompany::class);
});

test('a branch cannot be written for another company', function () {
    app(CurrentCompany::class)->runAs($this->alpha, fn () => Branch::query()->create([
        'company_id' => $this->bravo->id,
        'code' => 'SNK',
        'name' => 'Sneaky',
    ]));
})->throws(CompanyMismatch::class);

test('a tenant user only gets their own company\'s branches', function () {
    $this->actingAs($this->ownerOf($this->alpha))->get('/app')->assertInertia(fn ($page) => $page
        ->has('branches', 1)
        ->where('branches.0.code', 'ALP'));
});

test('company relations reach only that company\'s rows', function () {
    app(CurrentCompany::class)->runAs($this->alpha, function () {
        expect($this->alpha->branches()->count())->toBe(1)
            ->and($this->alpha->registers()->count())->toBe(2);
    });
});
