<?php

use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Plans\Actions\ArchivePlan;
use App\Domain\Plans\Models\Plan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(fn () => Mail::fake());

test('a plan with licences is in use and cannot be archived, even when they are revoked', function () {
    $company = $this->licensedTenant(tills: 1);
    $plan = $this->standardPlan();
    app(RevokeLicence::class)->handle($this->firstLicence($company), 'Closed');

    expect($plan->isInUse())->toBeTrue()
        ->and(fn () => app(ArchivePlan::class)->handle($plan))->toThrow(ValidationException::class, 'used by licences')
        ->and($plan->fresh()->trashed())->toBeFalse();
});

test('a plan without licences can be archived', function () {
    $this->licensedTenant(tills: 1);
    $unused = Plan::factory()->create();

    expect($unused->isInUse())->toBeFalse();
    app(ArchivePlan::class)->handle($unused);
    expect($unused->fresh()->trashed())->toBeTrue();
});

test('the archive button reports the plan is in use', function () {
    $this->licensedTenant(tills: 1);
    $plan = $this->standardPlan();

    $this->actingAs($this->admin(), 'admin')->from("/admin/plans/{$plan->id}")
        ->delete("/admin/plans/{$plan->id}")
        ->assertRedirect()
        ->assertSessionHasErrors('plan');

    expect($plan->fresh()->trashed())->toBeFalse();
});
