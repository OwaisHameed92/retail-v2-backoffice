<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Actions\AddRegister;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Actions\DeactivateRegister;
use App\Domain\Tenancy\Actions\ReactivateRegister;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Data\CompanyDetails;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

/** Plain keys in every message the array mailer has sent. */
function sentLicenceKeys(object $test): array
{
    $text = '';
    foreach (app('mailer')->getSymfonyTransport()->messages() as $message) {
        $text .= $message->getOriginalMessage()->toString();
    }

    return $test->keysIn(quoted_printable_decode($text));
}

test('creating a tenant issues a licence for every till on the chosen plan', function () {
    Mail::fake();
    $this->standardPlan();
    $pro = $this->proPlan();

    $company = app(CreateTenant::class)->handle(new NewTenant(
        company: new CompanyDetails(name: 'Khan Mini Mart'),
        branch: new BranchDetails(code: 'LDS', name: 'Leeds'),
        tills: 3,
        ownerName: 'Aisha Khan',
        ownerEmail: 'owner@khan.test',
        planId: $pro->id,
    ));

    $licences = Licence::withoutCompanyScope()->whereBelongsTo($company)->get();

    expect($company->fresh()->plan_id)->toBe($pro->id)
        ->and($licences)->toHaveCount(3)
        ->and($licences->pluck('plan_id')->unique()->all())->toBe([$pro->id])
        ->and($licences->pluck('status')->unique()->all())->toBe([LicenceStatus::Issued]);
});

test('the owner gets the welcome email with every till key, plus the separate set-password email', function () {
    Mail::fake();
    $this->standardPlan();

    $company = $this->tenant(tills: 3);

    Mail::assertQueued(SetPasswordMail::class, 1);
    Mail::assertQueued(WelcomeTenantMail::class, function (WelcomeTenantMail $mail) use ($company) {
        $hashes = Licence::withoutCompanyScope()->whereBelongsTo($company)->pluck('key_hash')->all();
        $matched = array_filter($mail->data->tills, fn ($till) => in_array(LicenceKey::parse($till->licenceKey)->hash(), $hashes, true));

        return $mail->hasTo('khan-mini-mart@owner.test')
            && count($mail->data->tills) === 3
            && count($matched) === 3
            && $mail->data->tills[0]->tillName === 'Till 1'
            && $mail->data->tills[0]->branchName === 'Leeds'
            && $mail->data->trialDays === 7
            && $mail->data->companyId === $company->id;
    });
});

test('an existing login added as owner still gets the keys, but no set-password email', function () {
    Mail::fake();
    $this->standardPlan();
    User::factory()->create(['email' => 'owner@khan.test', 'password' => Hash::make('their-own-password')]);

    app(CreateTenant::class)->handle($this->newTenant(ownerEmail: 'owner@khan.test'));

    Mail::assertNotQueued(SetPasswordMail::class);
    Mail::assertQueued(WelcomeTenantMail::class, fn (WelcomeTenantMail $mail) => $mail->hasTo('owner@khan.test') && count($mail->data->tills) === 2);
});

test('an active (paying) customer gets no trial line in the welcome email', function () {
    Mail::fake();
    $this->standardPlan();

    app(CreateTenant::class)->handle($this->newTenant(status: CompanyStatus::Active));

    Mail::assertQueued(WelcomeTenantMail::class, fn (WelcomeTenantMail $mail) => $mail->data->trialDays === null);
});

test('with no plan at all the tenant is created without licences or a welcome email', function () {
    Mail::fake();

    $company = $this->tenant();

    expect(Licence::withoutCompanyScope()->whereBelongsTo($company)->count())->toBe(0);
    Mail::assertNotQueued(WelcomeTenantMail::class);
    Mail::assertQueued(SetPasswordMail::class, 1);
});

test('the keys reach the owner but never the email log, audit log, queue or licence table', function () {
    $this->standardPlan();

    $company = $this->tenant(tills: 2);

    $keys = sentLicenceKeys($this);
    $hashes = Licence::withoutCompanyScope()->whereBelongsTo($company)->pluck('key_hash')->all();

    expect($keys)->toHaveCount(2)
        ->and(array_map(fn (string $key) => LicenceKey::parse($key)->hash(), $keys))->toEqualCanonicalizing($hashes)
        ->and(EmailLog::query()->where('template', 'welcome-tenant')->sole()->meta)->toMatchArray(['tills' => 2, 'business' => 'Khan Mini Mart']);

    $stored = $this->storedText();
    foreach ($keys as $key) {
        expect($stored)->not->toContain($key)->not->toContain(LicenceKey::parse($key)->body());
    }
});

test('a till added later gets its licence straight away', function () {
    Mail::fake();
    $company = $this->licensedTenant(tills: 1);

    $register = app(AddRegister::class)->handle($this->branchOf($company));

    expect($this->licenceOf($register)->status)->toBe(LicenceStatus::Issued);
    Mail::assertQueued(WelcomeTenantMail::class, 1);
});

test('a branch added with tills licenses each till', function () {
    Mail::fake();
    $company = $this->licensedTenant(tills: 1);

    $branch = app(AddBranch::class)->handle($company, new BranchDetails(code: 'BFD', name: 'Bradford'), 2);

    expect(Licence::withoutCompanyScope()->where('branch_id', $branch->id)->count())->toBe(2);
});

test('deactivating a till suspends its licence and reactivating lifts it', function () {
    Mail::fake();
    $company = $this->licensedTenant();
    $register = $this->registerOf($this->branchOf($company), '02');
    $licence = $this->activate($this->licenceOf($register));

    app(DeactivateRegister::class)->handle($register);
    expect($licence->fresh()->status)->toBe(LicenceStatus::Suspended)
        ->and($licence->fresh()->suspended_reason)->toBe(SuspendLicence::TILL_DEACTIVATED);

    app(ReactivateRegister::class)->handle($register->fresh());
    expect($licence->fresh()->status)->toBe(LicenceStatus::Trial)
        ->and($licence->fresh()->suspended_reason)->toBeNull();
});

test('reactivating a till keeps a suspension staff made for another reason', function () {
    Mail::fake();
    $company = $this->licensedTenant();
    $register = $this->registerOf($this->branchOf($company), '02');
    $licence = $this->licenceOf($register);
    app(SuspendLicence::class)->handle($licence, 'Unpaid invoice');

    app(DeactivateRegister::class)->handle($register);
    expect($licence->fresh()->suspended_reason)->toBe('Unpaid invoice');

    app(ReactivateRegister::class)->handle($register->fresh());
    expect($licence->fresh()->status)->toBe(LicenceStatus::Suspended)
        ->and($licence->fresh()->suspended_reason)->toBe('Unpaid invoice');
});

test('a till without a licence can be deactivated and reactivated', function () {
    Mail::fake();
    $company = $this->tenant(tills: 2);
    $register = $this->registerOf($this->branchOf($company), '02');

    app(DeactivateRegister::class)->handle($register);
    app(ReactivateRegister::class)->handle($register->fresh());

    expect(Register::withoutCompanyScope()->findOrFail($register->id)->is_active)->toBeTrue();
});

test('the create-tenant form takes a plan and rejects inactive ones', function () {
    Mail::fake();
    $this->withoutVite();
    $admin = $this->admin(AdminRole::Sales);
    $pro = $this->proPlan();
    $inactive = Plan::factory()->inactive()->create();
    $form = [
        'name' => 'Khan Mini Mart', 'status' => 'trial', 'branch_code' => 'LDS', 'branch_name' => 'Leeds', 'branch_nation' => 'england',
        'tills' => 1, 'owner_name' => 'Aisha Khan', 'owner_email' => 'aisha@khan.test',
    ];

    $this->actingAs($admin, 'admin')->post('/admin/tenants', $form + ['plan_id' => $inactive->id])->assertSessionHasErrors('plan_id');

    $this->actingAs($admin, 'admin')->post('/admin/tenants', $form + ['plan_id' => $pro->id])->assertRedirect();

    $company = Company::query()->where('name', 'Khan Mini Mart')->sole();
    expect($company->plan_id)->toBe($pro->id)
        ->and($this->firstLicence($company)->plan_id)->toBe($pro->id);
});
