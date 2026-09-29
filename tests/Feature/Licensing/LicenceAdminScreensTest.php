<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Licensing\Actions\ChangeLicencePlan;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Mailables\LicenceKeyMail;
use App\Domain\Mail\Mailables\LicenceRenewedMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
});

test('the licence list shows masked keys, the effective status and filter counts', function () {
    $company = $this->licensedTenant(tills: 2);
    $this->activate($this->firstLicence($company), deviceName: 'COUNTER-PC');
    app(SuspendCompany::class)->handle($other = $this->licensedTenant('Patel News', 1, 'PTL'), 'Unpaid');

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')
        ->get('/admin/licences?sort=company_name&direction=asc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/licences/index')
            ->has('licences.data', 3)
            ->where('licences.meta.total', 3)
            ->where('licences.data.0.company.name', 'Khan Mini Mart')
            ->where('licences.data.2.company.name', 'Patel News')
            ->where('licences.data.2.status', 'suspended')
            ->where('licences.data.2.statusReason', 'The business account is suspended.')
            ->where('counts.issued', 1)
            ->where('counts.trial', 1)
            ->where('counts.suspended', 1)
            ->where('total', 3)
            ->etc());

    $row = $this->get('/admin/licences')->viewData('page')['props']['licences']['data'][0];
    expect($row['maskedKey'])->toMatch('/^SSP-••••-••••-••••-[0-9A-Z]{4}$/')
        ->and($row)->not->toHaveKey('key_hash')->not->toHaveKey('keyHash')->not->toHaveKey('key');
});

test('the list filters by effective status, plan and business', function () {
    $company = $this->licensedTenant(tills: 2);
    $this->activate($this->firstLicence($company));
    $other = $this->licensedTenant('Patel News', 1, 'PTL');
    $pro = $this->proPlan();
    app(ChangeLicencePlan::class)->handle($this->firstLicence($other, 'PTL'), $pro);
    $admin = $this->admin();

    $this->actingAs($admin, 'admin')->get('/admin/licences?status=trial')
        ->assertInertia(fn (Assert $page) => $page->has('licences.data', 1)->where('filters.status', 'trial')->etc());

    $this->actingAs($admin, 'admin')->get("/admin/licences?plan={$pro->id}")
        ->assertInertia(fn (Assert $page) => $page->has('licences.data', 1)->where('licences.data.0.plan.name', 'Pro')->etc());

    $this->actingAs($admin, 'admin')->get("/admin/licences?company={$company->id}")
        ->assertInertia(fn (Assert $page) => $page->has('licences.data', 2)->where('filters.company.name', 'Khan Mini Mart')->etc());

    $this->actingAs($admin, 'admin')->get('/admin/licences?status=nonsense')->assertSessionHasErrors('status');
});

test('the list searches by last 4, device and business, never by a full key in the URL', function () {
    $company = $this->licensedTenant(tills: 1);
    $register = $this->registerOf($this->branchOf($company), '01');
    $this->firstLicence($company)->delete();
    $issued = $this->issue($register);
    $this->activate($issued->licence, deviceId: 'HW-7781-XYZ', deviceName: 'BACK-OFFICE');
    $this->licensedTenant('Patel News', 1, 'PTL');
    $admin = $this->admin();
    $key = LicenceKey::parse($issued->plainKey());

    foreach ([$key->last4(), strtolower($key->last4()), 'HW-7781', 'back-office', 'Khan'] as $term) {
        $this->actingAs($admin, 'admin')->get('/admin/licences?search='.urlencode($term))
            ->assertInertia(fn (Assert $page) => $page->has('licences.data', 1)->where('licences.data.0.id', $issued->licence->id)->etc());
    }

    // A full key in the query string is dropped (redirect without it), never searched or echoed.
    foreach ([$issued->plainKey(), strtolower(str_replace('-', ' ', $issued->plainKey()))] as $term) {
        $response = $this->actingAs($admin, 'admin')->get('/admin/licences?status=active&search='.urlencode($term));
        $response->assertRedirect(route('admin.licences.index', ['status' => 'active']))->assertSessionHas('error');
        expect((string) $response->getContent())->not->toContain($key->body());
    }

    $this->actingAs($admin, 'admin')->get('/admin/licences?search=nothing-like-this')
        ->assertInertia(fn (Assert $page) => $page->has('licences.data', 0)->etc());
});

test('the licence page shows the summary, timeline and activity', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()));
    app(SuspendLicence::class)->handle($licence, 'Checking the PC');

    $this->actingAs($this->admin(AdminRole::Support), 'admin')
        ->get("/admin/licences/{$licence->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/licences/show')
            ->where('licence.id', $licence->id)
            ->where('licence.status', 'suspended')
            ->where('licence.suspendedReason', 'Checking the PC')
            ->where('licence.deviceName', 'FRONT-TILL')
            ->where('licence.plan.name', 'Standard')
            ->has('licence.features', 2)
            ->where('can.manage', true)
            ->has('timeline')
            ->where('activity.0.action', 'licence.suspended')
            ->where('activity.0.description', 'Suspended the licence: Checking the PC')
            ->has('plans', 1));
});

test('accounts staff see the licence page without actions', function () {
    $licence = $this->firstLicence($this->licensedTenant());

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')->get("/admin/licences/{$licence->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.manage', false)->etc());
});

test('an unknown or deleted licence is a 404', function () {
    $licence = $this->firstLicence($this->licensedTenant());
    $licence->delete();
    $admin = $this->admin();

    $this->actingAs($admin, 'admin')->get("/admin/licences/{$licence->id}")->assertNotFound();
    $this->actingAs($admin, 'admin')->get('/admin/licences/01K5XTEST00000000000000000')->assertNotFound();
});

test('staff change a licence from its page', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()));
    $admin = $this->admin(AdminRole::Support);
    $this->actingAs($admin, 'admin')->from("/admin/licences/{$licence->id}");

    $this->post("/admin/licences/{$licence->id}/renew", ['term' => 'month', 'notify' => true])->assertRedirect()->assertSessionHas('success');
    expect($licence->fresh()->status)->toBe(LicenceStatus::Active);
    Mail::assertQueued(LicenceRenewedMail::class);

    $this->post("/admin/licences/{$licence->id}/renew", ['term' => 'until'])->assertSessionHasErrors('until');
    $this->post("/admin/licences/{$licence->id}/renew", ['term' => 'until', 'until' => '2001-01-01'])->assertSessionHasErrors('until');

    $this->post("/admin/licences/{$licence->id}/plan", ['plan_id' => $this->proPlan()->id])->assertSessionHas('success');
    expect($licence->fresh()->plan->code)->toBe('pro');

    $this->post("/admin/licences/{$licence->id}/release")->assertSessionHas('success');
    expect($licence->fresh()->device_id)->toBeNull();

    $this->post("/admin/licences/{$licence->id}/suspend", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post("/admin/licences/{$licence->id}/suspend", ['reason' => 'Fraud check'])->assertSessionHas('success');
    $this->post("/admin/licences/{$licence->id}/unsuspend")->assertSessionHas('success');

    $this->put("/admin/licences/{$licence->id}/notes", ['notes' => 'Replaced PSU in March'])->assertSessionHas('success');
    expect($licence->fresh()->notes)->toBe('Replaced PSU in March');

    $this->post("/admin/licences/{$licence->id}/revoke", ['reason' => 'Shop sold'])->assertSessionHas('success');
    $this->post("/admin/licences/{$licence->id}/suspend", ['reason' => 'Again'])->assertSessionHasErrors('status');

    expect(AuditLog::query()->where('subject_id', $licence->id)->where('actor_id', $admin->id)->pluck('action')->all())
        ->toContain('licence.renewed', 'licence.plan_changed', 'licence.device_released', 'licence.suspended', 'licence.unsuspended', 'licence.notes_updated', 'licence.revoked');
});

test('reissuing answers with the new key once, as JSON that is never cached', function () {
    $licence = $this->firstLicence($this->licensedTenant());

    $response = $this->actingAs($this->admin(AdminRole::Support), 'admin')
        ->postJson("/admin/licences/{$licence->id}/reissue")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('keys.0.licenceId', $licence->id)
        ->assertJsonPath('keys.0.replacedKey', true)
        ->assertJsonPath('keys.0.tillName', 'Till 1');

    $key = LicenceKey::parse($response->json('keys.0.key'));
    expect($key->matches($licence->fresh()->key_hash))->toBeTrue()
        ->and($this->storedText())->not->toContain($key->body());

    $this->get("/admin/licences/{$licence->id}")->assertDontSee($key->formatted())->assertDontSee($key->body());
});

test('issue missing licences covers every active till without one', function () {
    $company = $this->tenant(tills: 2);
    $admin = $this->admin(AdminRole::Support);

    $this->actingAs($admin, 'admin')->postJson("/admin/tenants/{$company->id}/licences/issue-missing")
        ->assertUnprocessable()->assertJsonValidationErrors('plan');

    $this->standardPlan();
    $response = $this->actingAs($admin, 'admin')->postJson("/admin/tenants/{$company->id}/licences/issue-missing")
        ->assertOk()->assertJsonPath('message', '2 licences issued.')->assertJsonCount(2, 'keys');

    foreach ($response->json('keys') as $row) {
        expect(LicenceKey::parse($row['key'])->matches(Licence::withoutCompanyScope()->findOrFail($row['licenceId'])->key_hash))->toBeTrue();
    }

    $this->postJson("/admin/tenants/{$company->id}/licences/issue-missing")->assertOk()
        ->assertJsonPath('message', 'Every active till already has a licence.')->assertJsonCount(0, 'keys');
});

test('a till whose licence was revoked can be issued a new one from the tenant page', function () {
    $company = $this->licensedTenant();
    $register = $this->registerOf($this->branchOf($company), '02');
    app(RevokeLicence::class)->handle($this->licenceOf($register), 'Lost');
    $admin = $this->admin();

    $this->actingAs($admin, 'admin')->postJson("/admin/tenants/{$company->id}/registers/{$register->id}/licence")
        ->assertOk()->assertJsonPath('keys.0.tillCode', '02');

    $this->postJson("/admin/tenants/{$company->id}/registers/{$register->id}/licence")
        ->assertUnprocessable()->assertJsonValidationErrors('register');

    $other = $this->licensedTenant('Patel News', 1, 'PTL');
    $this->postJson("/admin/tenants/{$other->id}/registers/{$register->id}/licence")->assertNotFound();
});

test('adding a till or branch from the tenant page answers with the new keys when asked for JSON', function () {
    $company = $this->licensedTenant(tills: 1);
    $branch = $this->allowTills($this->branchOf($company), 3);
    $admin = $this->admin(AdminRole::Sales);

    $till = $this->actingAs($admin, 'admin')->postJson("/admin/tenants/{$company->id}/branches/{$branch->id}/registers", ['name' => 'Kiosk'])
        ->assertOk()->assertJsonPath('message', 'Kiosk added.')->assertJsonCount(1, 'keys')->assertJsonPath('keys.0.tillName', 'Kiosk');
    expect(LicenceKey::isValid($till->json('keys.0.key')))->toBeTrue();

    $this->postJson("/admin/tenants/{$company->id}/branches", ['code' => 'BFD', 'name' => 'Bradford', 'nation' => 'england', 'tills' => 3])
        ->assertOk()->assertJsonCount(3, 'keys');

    // The classic form post still redirects, without any key.
    $this->post("/admin/tenants/{$company->id}/branches/{$branch->id}/registers", ['name' => 'Back'])
        ->assertRedirect()->assertSessionHas('success', 'Back added.');
});

test('renew all and the company plan are changed from the tenant page', function () {
    $company = $this->licensedTenant(tills: 2);
    $pro = $this->proPlan();
    $admin = $this->admin(AdminRole::Support);

    $this->actingAs($admin, 'admin')->post("/admin/tenants/{$company->id}/licences/renew", ['term' => 'year', 'notify' => false])
        ->assertRedirect()->assertSessionHas('success');
    expect(Licence::withoutCompanyScope()->whereBelongsTo($company)->whereNotNull('expires_at')->count())->toBe(2);
    Mail::assertNotQueued(LicenceRenewedMail::class);

    $this->put("/admin/tenants/{$company->id}/plan", ['plan_id' => $pro->id])->assertSessionHas('success', 'New tills get Pro.');
    expect($company->fresh()->plan_id)->toBe($pro->id)
        ->and($this->firstLicence($company)->plan_id)->not->toBe($pro->id);

    $this->put("/admin/tenants/{$company->id}/plan", ['plan_id' => $pro->id, 'apply_to_licences' => true])->assertSessionHas('success', 'New tills get Pro. 2 licences moved to it.');
    expect(Licence::withoutCompanyScope()->whereBelongsTo($company)->where('plan_id', $pro->id)->count())->toBe(2);
});

test('the tenant page shows its licences and each till licence', function () {
    $company = $this->licensedTenant(tills: 2);
    $register = $this->registerOf($this->branchOf($company), '01');
    $licence = $this->licenceOf($register);

    $this->actingAs($this->admin(AdminRole::Sales), 'admin')->get("/admin/tenants/{$company->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('licensing.licences', 2)
            ->where('licensing.summary.missing', 0)
            ->where('licensing.summary.live', 2)
            ->where('licensing.summary.renewable', 2)
            ->where('licensing.summary.counts.issued', 2)
            ->where('licensing.plan.current.name', 'Standard')
            ->where("licensing.tillLicences.{$register->id}.id", $licence->id)
            ->where("licensing.tillLicences.{$register->id}.maskedKey", $licence->maskedKey())
            ->where('can.manageLicences', false)
            ->etc());
});

test('email keys sends the shown keys to every owner, never logging them', function () {
    $company = $this->licensedTenant(tills: 1);
    $this->addMember($company, CompanyRole::Owner);
    $register = $this->registerOf($this->branchOf($company), '01');
    $this->licenceOf($register)->delete();
    $issued = $this->issue($register);

    $this->actingAs($this->admin(AdminRole::Sales), 'admin')
        ->postJson('/admin/licences/email-keys', ['licences' => [['id' => $issued->licence->id, 'key' => strtolower($issued->plainKey())]]])
        ->assertOk()->assertJsonPath('owners', 2)->assertJsonPath('message', 'Emailed to 2 owners.');

    Mail::assertQueued(LicenceKeyMail::class, 2);
    Mail::assertQueued(LicenceKeyMail::class, fn (LicenceKeyMail $mail) => $mail->data->tills[0]->licenceKey === $issued->plainKey()
        && ! str_contains(json_encode($mail->logMeta()), $issued->plainKey()));

    $entry = AuditLog::query()->where('action', 'licence.key_emailed')->sole();
    expect($entry->meta)->toBe(['owners' => 2, 'key_last4' => $issued->licence->key_last4])
        ->and($this->storedText())->not->toContain(LicenceKey::parse($issued->plainKey())->body());
});

test('email keys reads keys from the body only, never the query string', function () {
    $register = $this->registerOf($this->branchOf($this->licensedTenant(tills: 1)), '01');
    $this->licenceOf($register)->delete();
    $issued = $this->issue($register);
    $query = http_build_query(['licences' => [['id' => $issued->licence->id, 'key' => $issued->plainKey()]]]);

    $this->actingAs($this->admin(AdminRole::Sales), 'admin')
        ->postJson('/admin/licences/email-keys?'.$query, [])
        ->assertUnprocessable()->assertJsonValidationErrors('licences');

    expect(AuditLog::query()->where('action', 'licence.key_emailed')->exists())->toBeFalse();
});

test('email keys refuses keys that do not match, mixed businesses, revoked licences and businesses without an owner', function () {
    $company = $this->licensedTenant(tills: 1);
    $register = $this->registerOf($this->branchOf($company), '01');
    $this->licenceOf($register)->delete();
    $issued = $this->issue($register);
    $other = $this->licensedTenant('Patel News', 1, 'PTL');
    $otherRegister = $this->registerOf($this->branchOf($other, 'PTL'), '01');
    $this->licenceOf($otherRegister)->delete();
    $otherIssued = $this->issue($otherRegister);
    $admin = $this->admin(AdminRole::Support);
    $send = fn (array $rows) => $this->actingAs($admin, 'admin')->postJson('/admin/licences/email-keys', ['licences' => $rows]);

    $send([['id' => $issued->licence->id, 'key' => LicenceKey::generate()->formatted()]])->assertUnprocessable()->assertJsonValidationErrors('licences');
    $send([['id' => $issued->licence->id, 'key' => 'not a key']])->assertUnprocessable();
    $send([['id' => $issued->licence->id, 'key' => $issued->plainKey()], ['id' => $otherIssued->licence->id, 'key' => $otherIssued->plainKey()]])
        ->assertUnprocessable()->assertJsonPath('errors.licences.0', 'Keys of different businesses cannot go in one email.');
    $send([])->assertUnprocessable();

    $company->users()->updateExistingPivot($this->ownerOf($company)->id, ['is_active' => false]);
    $send([['id' => $issued->licence->id, 'key' => $issued->plainKey()]])->assertUnprocessable()->assertJsonPath('errors.licences.0', 'Khan Mini Mart has no active owner to email. Add an owner first.');

    app(RevokeLicence::class)->handle($otherIssued->licence, 'Gone');
    $send([['id' => $otherIssued->licence->id, 'key' => $otherIssued->plainKey()]])->assertUnprocessable();

    Mail::assertNotQueued(LicenceKeyMail::class);
});

test('keys sent to the email endpoint are never flashed to the session', function () {
    $company = $this->licensedTenant(tills: 1);
    $licence = $this->firstLicence($company);
    $key = LicenceKey::generate()->formatted();

    $this->actingAs($this->admin(), 'admin')->from('/admin/licences')
        ->post('/admin/licences/email-keys', ['licences' => [['id' => $licence->id, 'key' => $key]]])
        ->assertRedirect();

    expect(json_encode(session()->all()))->not->toContain($key);
});

test('the licence detail dates are ISO-8601 UTC', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 12:00:00'));
    $licence = $this->activate($this->firstLicence($this->licensedTenant()));

    $this->actingAs($this->admin(), 'admin')->get("/admin/licences/{$licence->id}")
        ->assertInertia(fn (Assert $page) => $page->where('licence.trialEndsAt', '2027-03-17T12:00:00+00:00')->etc());
});
