<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Models\PartnerWebhookEvent;
use App\Domain\Plans\Enums\SetupFeeMode;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Partners\PartnerTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Admin → Partner requests: review, approve (the normal onboarding: identical to the new-business wizard) and reject.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, PartnerTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    Http::preventStrayRequests();
    Http::fake(['zivaro.test/*' => Http::response(['ok' => true])]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));
    $this->setVat(true);
    $this->standardPlan();
    $this->setupOnlyPlan();
});

/** The key facts of a business, to compare one made by the wizard with one made from a partner request. */
function businessFacts(Company $company): array
{
    $company->refresh();
    $branches = Branch::withoutCompanyScope()->where('company_id', $company->id)->orderBy('code')->get();
    $registers = Register::withoutCompanyScope()->where('company_id', $company->id)->orderBy('code')->get();
    $licences = Licence::withoutCompanyScope()->where('company_id', $company->id)->orderBy('register_id')->get();
    $account = app(BillingAccounts::class)->for($company);
    $owner = $company->owners()->first();

    return [
        'company' => [
            'name' => $company->name, 'legal_name' => $company->legal_name, 'vat_number' => $company->vat_number,
            'company_number' => $company->company_number, 'email' => $company->email, 'phone' => $company->phone,
            'contact_name' => $company->contact_name, 'address' => $company->address, 'town' => $company->town,
            'postcode' => $company->postcode, 'owner_name' => $company->owner_name, 'status' => $company->status->value,
            'trial_ends_at' => $company->trial_ends_at, 'activated_at' => $company->activated_at, 'plan_id' => $company->plan_id,
            'multi_branch' => $company->multi_branch, 'max_branches' => $company->max_branches,
        ],
        'branches' => $branches->map(fn (Branch $b) => [$b->code, $b->name, $b->nation->value, $b->address, $b->town, $b->postcode, $b->max_registers])->all(),
        'registers' => $registers->map(fn (Register $r) => [$r->code, $r->name, $r->is_main_till, $r->is_active])->all(),
        'licences' => $licences->map(fn (Licence $l) => [$l->plan_id, $l->status->value, $l->kind ?? null, $l->activated_at, $l->expires_at, $l->trial_ends_at])->all(),
        'owner' => [$owner?->name, $owner?->email],
        'billing' => [
            $account->billing_mode->value, $account->mandate_deadline_at?->toIso8601String(), $account->setup_fee_override,
            $account->till_setup_fee_override, $account->vat_applies, $account->setup_fee_instalments,
        ],
        'setupFee' => SetupFeeState::for($company, $account)->total,
    ];
}

test('approve creates the same business as the new-business wizard', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody(['setupFeePerTill' => null]))->assertCreated();
    $request = $this->requestFor();
    $form = $this->reviewForm($request);

    // The wizard, with the same answers (one shop: the wizard's first branch).
    $shop = $form['shops'][0];
    $this->actingAs($this->admin(), 'admin')->post('/admin/tenants', [
        ...array_diff_key($form, array_flip(['shops', 'setup_fee_per_till', 'vat_applies'])),
        'owner_email' => 'wizard@example.co.uk',
        'branch_code' => $shop['code'], 'branch_name' => $shop['name'], 'branch_nation' => $shop['nation'], 'branch_address' => $shop['address'],
        'branch_town' => $shop['town'], 'branch_postcode' => $shop['postcode'], 'tills' => $shop['tills'], 'max_registers' => $shop['tills_allowed'],
    ])->assertSessionHasNoErrors()->assertRedirect();
    $wizard = Company::query()->sole();

    $this->approveAs($request, overrides: ['owner_email' => 'partner@example.co.uk'])->assertSessionHasNoErrors()->assertRedirect();
    $partnerMade = Company::query()->whereKeyNot($wizard->id)->sole();

    $wizardFacts = businessFacts($wizard);
    $partnerFacts = businessFacts($partnerMade);
    expect($partnerFacts['owner'])->toBe(['Sandra Smith', 'partner@example.co.uk'])
        ->and($wizardFacts['owner'])->toBe(['Sandra Smith', 'wizard@example.co.uk']);
    unset($wizardFacts['owner'], $partnerFacts['owner']);
    expect($partnerFacts)->toEqual($wizardFacts)
        ->and($partnerFacts['company']['name'])->toBe('Istanbul Market')
        ->and($partnerFacts['registers'])->toHaveCount(2)
        ->and($partnerFacts['setupFee'])->toBe('1440.00'); // the plan's £1,200 + VAT
});

test('approving creates the business, links the request and tells the partner', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $request = $this->requestFor();
    $admin = $this->admin(AdminRole::Sales);

    $this->actingAs($admin, 'admin')->post(route('admin.partner-requests.approve', $request), $this->reviewForm($request))
        ->assertSessionHasNoErrors()->assertRedirect();

    $company = Company::query()->sole();
    $request->refresh();
    expect($request->status)->toBe(PartnerRequestStatus::Approved)
        ->and($request->company_id)->toBe($company->id)
        ->and($request->approved_by_admin_id)->toBe($admin->id)
        ->and($company->notes)->toContain('From Zivaro deal zivaro-deal-42, sold by Ali Khan (ali@switchandsave.co.uk).')
        ->and($company->notes)->toContain('Sale price £1,500.00, signed on 12 October 2026.')
        ->and(User::query()->where('email', 'sandra@example.co.uk')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'partner_request.approved')->where('company_id', $company->id)->exists())->toBeTrue();
    Mail::assertQueued(SetPasswordMail::class, fn (SetPasswordMail $mail) => $mail->hasTo('sandra@example.co.uk'));

    $event = PartnerWebhookEvent::query()->sole();
    expect($event->type->value)->toBe('tenant.approved')->and($event->delivered_at)->not->toBeNull();

    $this->getDeal($key)->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('tenantId', $company->id)
        ->assertJsonPath('adminUrl', url('/admin/tenants/'.$company->id))
        ->assertJsonPath('tills', 2)
        ->assertJsonPath('setupFee', ['total' => '1800.00', 'paid' => false, 'paidAt' => null]);
});

test('the agreed setup fee per till becomes the onboarding fee and the fee for tills added later', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->approveAs($this->requestFor())->assertSessionHasNoErrors();

    $company = Company::query()->sole();
    $account = app(BillingAccounts::class)->for($company);
    expect($account->setup_fee_override)->toBe('1500.00')
        ->and($account->till_setup_fee_override)->toBe('750.00')
        ->and($account->billing_mode)->toBe(BillingMode::DirectDebit)
        ->and(SetupFeeState::for($company, $account)->total)->toBe('1800.00');
});

test('a per-till plan without an agreed fee charges the plan fee for each till; no VAT when the deal says so', function () {
    $this->setupOnlyPlan()->forceFill(['setup_fee' => '400.00', 'setup_fee_mode' => SetupFeeMode::PerTill])->save();
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody(['setupFeePerTill' => null, 'vatApplies' => false, 'shops.0.tills' => 3]))->assertCreated();
    $this->approveAs($this->requestFor())->assertSessionHasNoErrors();

    $company = Company::query()->sole();
    $account = app(BillingAccounts::class)->for($company);
    expect($account->setup_fee_override)->toBeNull()
        ->and($account->vat_applies)->toBeFalse()
        ->and(SetupFeeState::for($company, $account)->total)->toBe('1200.00');
});

test('several shops become branches with their tills; the business may run them all', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody(['shops' => [
        ['name' => 'Birmingham', 'address' => '1 High Street', 'town' => 'Birmingham', 'postcode' => 'B1 1AA', 'tills' => 2],
        ['name' => 'Bromsgrove', 'address' => '4 Market St', 'town' => 'Bromsgrove', 'postcode' => 'B61 8AA', 'tills' => 1],
    ]]))->assertCreated();
    $request = $this->requestFor();
    expect(collect($this->reviewForm($request)['shops'])->pluck('code')->all())->toBe(['BRM', 'BRA']);

    $this->approveAs($request)->assertSessionHasNoErrors();

    $company = Company::query()->sole();
    $branches = Branch::withoutCompanyScope()->where('company_id', $company->id)->orderBy('name')->get();
    expect($branches->pluck('name')->all())->toBe(['Birmingham', 'Bromsgrove'])
        ->and(Register::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(3)
        ->and($company->multi_branch)->toBeTrue()
        ->and($company->max_branches)->toBe(2)
        ->and(app(BillingAccounts::class)->for($company)->setup_fee_override)->toBe('2250.00');
});

test('the admin may edit every field before approving', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();

    $this->approveAs($this->requestFor(), overrides: [
        'name' => 'Istanbul Supermarket', 'shops.0.code' => 'IST', 'shops.0.tills' => 1, 'setup_fee_per_till' => '0', 'status' => 'active',
    ])->assertSessionHasNoErrors();

    $company = Company::query()->sole();
    expect($company->name)->toBe('Istanbul Supermarket')
        ->and($company->status->value)->toBe('active')
        ->and(Branch::withoutCompanyScope()->where('company_id', $company->id)->sole()->code)->toBe('IST')
        // Nothing to pay: the partner hears the setup fee is settled straight away.
        ->and(PartnerWebhookEvent::query()->pluck('type')->map->value->all())->toBe(['tenant.approved', 'tenant.setup_fee_paid']);
});

test('the review form is checked like the wizard and nothing is created on an error', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();

    $this->approveAs($this->requestFor(), overrides: ['shops.0.postcode' => 'NOPE', 'owner_email' => '', 'shops.0.code' => 'x'])
        ->assertSessionHasErrors([
            'shops.0.postcode' => 'Enter a UK postcode like LS1 6AB.',
            'owner_email' => 'Enter the owner’s email. We send them a link to set their password.',
            'shops.0.code' => 'Use 2 to 5 capital letters, for example LDS.',
        ]);

    expect(Company::query()->count())->toBe(0)->and($this->requestFor()->status)->toBe(PartnerRequestStatus::PendingReview);
});

test('a request is approved once: a second approval, or approving a rejected or cancelled one, is refused', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->putDeal($key, $this->dealBody(), 'zivaro-deal-43')->assertCreated();
    $this->putDeal($key, $this->dealBody(), 'zivaro-deal-44')->assertCreated();
    $request = $this->requestFor();
    $form = $this->reviewForm($request);

    $this->approveAs($request)->assertSessionHasNoErrors();
    $this->actingAs($this->admin(), 'admin')->post(route('admin.partner-requests.approve', $request), $form)
        ->assertSessionHasErrors(['status' => 'This request is already approved.']);

    $this->actingAs($this->admin(), 'admin')->post(route('admin.partner-requests.reject', $this->requestFor('zivaro-deal-43')), ['reason' => 'Duplicate'])->assertRedirect();
    $this->approveAs($this->requestFor('zivaro-deal-43'))->assertSessionHasErrors(['status' => 'This request was rejected.']);

    $this->cancelDeal($key, 'zivaro-deal-44')->assertOk();
    $this->approveAs($this->requestFor('zivaro-deal-44'))->assertSessionHasErrors(['status' => 'The partner cancelled this request.']);

    expect(Company::query()->count())->toBe(1);
});

test('rejecting needs a reason the partner then reads', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $request = $this->requestFor();

    $this->actingAs($this->admin(), 'admin')->post(route('admin.partner-requests.reject', $request), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->admin(), 'admin')->post(route('admin.partner-requests.reject', $request), ['reason' => 'The business already has an account with us.'])
        ->assertRedirect(route('admin.partner-requests.show', $request));

    $this->getDeal($key)->assertJsonPath('status', 'rejected')->assertJsonPath('rejectedReason', 'The business already has an account with us.');
    expect(PartnerWebhookEvent::query()->sole()->type->value)->toBe('tenant.rejected')
        ->and(Company::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'partner_request.rejected')->exists())->toBeTrue();
});

test('the list and the review page show the request, the seller and the pre-filled form', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $request = $this->requestFor();
    $this->actingAs($this->admin(AdminRole::Support), 'admin');

    $this->get(route('admin.partner-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/partner-requests/index')
        ->where('requests.meta.total', 1)
        ->where('requests.data.0.businessName', 'Istanbul Market')
        ->where('requests.data.0.partner', 'Zivaro')
        ->where('requests.data.0.status', 'pendingReview')
        ->where('counts.pendingReview', 1)
        ->where('admin.navCounts.partnerRequests', 1));

    $this->get(route('admin.partner-requests.index', ['status' => 'approved']))->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 0));
    $this->get(route('admin.partner-requests.index', ['search' => 'ali@switch']))->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 1));

    $this->get(route('admin.partner-requests.show', $request))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/partner-requests/show')
        ->where('request.sellerName', 'Ali Khan')
        ->where('request.payload.owner.phone', '07700 900123')
        ->where('form.name', 'Istanbul Market')
        ->where('form.owner_email', 'sandra@example.co.uk')
        ->where('form.shops.0.code', 'BRM')
        ->where('form.shops.0.tills', 2)
        ->where('form.setup_fee_per_till', '750.00')
        ->where('form.plan_id', $this->setupOnlyPlan()->id)
        ->has('options.licenceOptions')
        ->where('can.manage', true));
});

test('an approved request the partner cancelled shows the warning data and no form', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->approveAs($this->requestFor())->assertSessionHasNoErrors();
    $this->cancelDeal($key)->assertOk();

    $this->actingAs($this->admin(), 'admin')->get(route('admin.partner-requests.show', $this->requestFor()))->assertInertia(fn (Assert $page) => $page
        ->where('request.status', 'approved')
        ->where('request.partnerCancelled', true)
        ->where('request.cancelledReason', 'Customer pulled out')
        ->where('form', null)
        ->has('webhooks', 1));

    $this->get(route('admin.partner-requests.index', ['status' => 'partnerCancelled']))->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 1));
});
