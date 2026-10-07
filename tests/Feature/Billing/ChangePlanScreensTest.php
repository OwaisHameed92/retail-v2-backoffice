<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Licensing\Actions\UpdateBranchLimits;
use App\Domain\Mail\Actions\UpdateEmailSettings;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Mail\Models\HeldEmail;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\ChangePlanHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class, LicenceApiHelpers::class, ChangePlanHelpers::class);

/*
 * Change plan (owner 2026-10-07): the preview writes nothing and says what will happen; billing admins only; a shop
 * with its own features keeps them; the tills get a new token; the notice email is held while reminders are held.
 */
beforeEach(function () {
    $this->withoutVite();
    config(['mail.default' => 'array']);
    $this->fakeGoCardless();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
});

/** Row counts and the latest change of every table a plan change writes to. */
function planChangeFootprint(): array
{
    return collect(['invoices', 'invoice_lines', 'audit_logs', 'billing_accounts', 'email_logs', 'held_emails', 'payments'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
        ->merge([
            'licences' => DB::table('licences')->orderBy('id')->get(['id', 'plan_id', 'features', 'expires_at', 'grace_days'])->toJson(),
            'companies' => DB::table('companies')->orderBy('id')->get(['id', 'plan_id'])->toJson(),
        ])->all();
}

test('the preview writes nothing and says in plain words what the change does', function () {
    $company = $this->cpOnboarded(PlanBillingType::SetupOnly);
    $to = $this->cpPlan('Setup + monthly', PlanBillingType::SetupAndRecurring, '1200.00', '12.00', [Feature::Loyalty, Feature::Promotions]);
    $before = planChangeFootprint();
    $calls = $this->gc->calls;

    $preview = $this->previewPlan($company, $to)->assertOk()->json();

    expect(planChangeFootprint())->toBe($before)
        ->and($this->gc->calls)->toBe($calls)
        ->and($preview['from']['name'])->toBe('Standard')
        ->and($preview['to']['name'])->toBe('Setup + monthly')
        ->and($preview['blocked'])->toBeNull()
        ->and($preview['features']['gained'])->toBe([Feature::Promotions->label()])
        ->and($preview['setupFee']['applies'])->toBeFalse()
        ->and($preview['setupFee']['lines'][0])->toBe('Nothing to charge: the setup fee already paid covers all 2 tills.')
        ->and($preview['recurring']['lines'][0])->toBe('£28.80 per month (£12.00 per till + VAT) starts today. The first period is 24 Oct – 23 Nov 2026 (£28.80 for 2 tills).')
        ->and(implode(' ', $preview['recurring']['lines']))->toContain('A Direct Debit is needed')->toContain('Deadline 27 October 2026')
        ->and(implode(' ', $preview['licences']['lines']))->toContain('stay valid to 23 Nov 2026')->toContain('No till locks at the change')
        ->and($preview['invoices'])->toHaveCount(1)
        ->and($preview['invoices'][0]['amount'])->toBe('£28.80')
        ->and($preview['email'])->toContain('Your plan has changed');
});

test('the preview shows the setup fee for the tills not covered and follows the amount typed', function () {
    $company = $this->payingTenant();
    $to = $this->cpPlan('Setup + monthly', PlanBillingType::SetupAndRecurring, '1200.00', '12.00');

    $this->previewPlan($company, $to, ['setup_fee' => '£600'])->assertOk()
        ->assertJsonPath('setupFee.applies', true)
        ->assertJsonPath('setupFee.suggested', '1200.00')
        ->assertJsonPath('setupFee.amount', '600.00')
        ->assertJsonPath('setupFee.gross', '£720.00')
        ->assertJsonPath('invoices.0.title', 'Setup fee')
        ->assertJsonPath('invoices.0.amount', '£720.00');

    $this->previewPlan($company, $to, ['setup_fee' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('setup_fee');
});

test('only billing admins can preview or change a plan; tenant users and guests cannot', function () {
    $company = $this->payingTenant();
    $to = $this->cpPlan('Monthly plus', PlanBillingType::RecurringOnly, '0.00', '40.00');

    $this->post(route('admin.tenants.plan-change.store', $company), ['plan_id' => $to->id])->assertRedirect(route('admin.login'));
    $this->getJson(route('admin.tenants.plan-change.preview', ['company' => $company->id, 'plan_id' => $to->id]))->assertUnauthorized();

    $owner = $this->addMember($company, CompanyRole::Owner);
    $this->actingAs($owner)->post(route('admin.tenants.plan-change.store', $company), ['plan_id' => $to->id])->assertRedirect(route('admin.login'));
    $this->actingAs($owner)->get(route('admin.tenants.plan-change.preview', ['company' => $company->id, 'plan_id' => $to->id]))->assertRedirect(route('admin.login'));

    foreach ([AdminRole::Support, AdminRole::Sales] as $role) {
        $this->previewPlan($company, $to, role: $role)->assertForbidden();
        $this->changePlan($company, $to, role: $role)->assertForbidden();
    }

    expect($this->licencesOf($company)[0]->plan_id)->toBe($this->standardPlan()->id);

    $this->previewPlan($company, $to, role: AdminRole::Owner)->assertOk();
    $this->changePlan($company, $to, role: AdminRole::Owner)->assertRedirect()->assertSessionHasNoErrors();
});

test('the Billing tab offers the active plans to billing admins', function () {
    $company = $this->payingTenant();
    $this->cpPlan('Monthly plus', PlanBillingType::RecurringOnly, '0.00', '40.00');

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')->get(route('admin.tenants.show', $company))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('billing.planChange.currentPlanId', $this->standardPlan()->id)
            ->has('billing.planChange.plans', 2));
});

test('a shop with its own features keeps them; a shop following the plan gets the new features; tills get a new token', function () {
    $this->withSigningKey();
    [$company, $licence] = $this->keyedTenant(tills: 1);
    $token = $this->activateTill()->assertOk()->json('licenceToken');
    app(UpdateBranchLimits::class)->handle($company, true, 2);
    $bradford = app(AddBranch::class)->handle($company->fresh(), new BranchDetails(code: 'BFD', name: 'Bradford'), 1);
    $bradford->forceFill(['licence_features' => ['second_screen']])->save();
    $this->branchOf($company)->forceFill(['licence_features' => null])->save();
    $to = $this->cpPlan('Pro monthly', PlanBillingType::RecurringOnly, '0.00', '40.00', [Feature::Loyalty, Feature::Promotions, Feature::Purchasing]);

    $this->previewPlan($company, $to)->assertOk()
        ->assertJsonPath('features.customShops.0.name', 'Bradford')
        ->assertJsonPath('features.customShops.0.features', [Feature::SecondScreen->label()]);
    $this->changePlan($company, $to)->assertRedirect()->assertSessionHasNoErrors();

    $bradfordTill = $this->licenceOf($this->registerOf($bradford, '01'));
    expect($bradfordTill->features->all())->toBe([Feature::SecondScreen])
        ->and($bradfordTill->plan_id)->toBe($to->id)
        ->and($licence->refresh()->features->all())->toBe([Feature::Loyalty, Feature::Promotions, Feature::Purchasing]);

    $new = $this->validateTill($licence->id, $token)->assertOk()->json('licenceToken');
    expect($new)->toBeString()->not->toBe($token)
        ->and($this->verifyToken($this->validateTill($licence->id, $token))->payload['features'])->toContain('purchasing');
});

test('the “Your plan has changed” email is held while reminders and notices are not sent automatically', function () {
    app(UpdateEmailSettings::class)->handle([EmailCategory::Reminders->value => false]);
    $company = $this->payingTenant();
    $to = $this->cpPlan('Monthly plus', PlanBillingType::RecurringOnly, '0.00', '40.00');

    $this->previewPlan($company, $to)->assertOk()->assertJsonPath('email', fn (string $text) => str_contains($text, 'is held until you send it'));
    $this->changePlan($company, $to)->assertRedirect();

    $held = HeldEmail::query()->where('company_id', $company->id)->sole();
    expect($held->template)->toBe('plan-changed')
        ->and(EmailLog::query()->where('template', 'plan-changed')->sole()->status)->toBe(EmailStatus::Held);
});
