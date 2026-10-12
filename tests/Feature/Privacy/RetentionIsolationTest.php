<?php

use App\Domain\Privacy\Actions\SavePrivacySettings;
use App\Domain\Privacy\Models\PrivacySettings;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Exceptions;
use Tests\Feature\Shared\FailingBusiness;
use Tests\Feature\TillData\TillFixtures;

/*
 * Audit DATA-8: privacy:retention isolates its businesses. One business that fails is reported with its company id,
 * shown as failed, the others are still checked (and anonymised where turned on), and the command exits non-zero.
 */

beforeEach(function () {
    Exceptions::fake();
    $this->travelTo('2026-10-24 12:00:00');
    [$first] = TillFixtures::tenant();
    $second = Company::factory()->create(['name' => 'Other Stores']);

    foreach ([$first, $second] as $company) {
        app(SavePrivacySettings::class)->handle($company, 24, true, null);
    }

    PrivacySettings::withoutCompanyScope()->update(['last_checked_at' => null]); // saving checks once; the run checks again

    $this->ids = [$first->id, $second->id];
    $this->checked = fn (string $id) => PrivacySettings::withoutCompanyScope()->where('company_id', $id)->sole()->last_checked_at;
});

test('one failing business does not stop the others, and the command exits non-zero naming it', function () {
    $failing = FailingBusiness::on('company_privacy_settings', $this->ids);

    $this->artisan('privacy:retention', ['--apply' => true])->expectsOutputToContain('failed for 1 business(es)')->assertFailed();
    $failing->stop();

    expect(($this->checked)($failing->other($this->ids)))->not->toBeNull()
        ->and(($this->checked)((string) $failing->failed))->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === "Simulated failure for business {$failing->failed}");
});

test('a healthy run still exits successfully', function () {
    $this->artisan('privacy:retention', ['--apply' => true])->assertSuccessful();

    expect(($this->checked)($this->ids[0]))->not->toBeNull()->and(($this->checked)($this->ids[1]))->not->toBeNull();
    Exceptions::assertNothingReported();
});
