<?php

use App\Domain\Leads\Actions\ApproveTrial;
use App\Domain\Leads\Actions\RejectLead;
use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadNote;
use App\Domain\Leads\Support\TrialSuggestion;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Data\TenantActivity;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Leads\LeadTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LeadTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->plan = $this->standardPlan();
    $this->sam = $this->salesAdmin();
    $this->actingAs($this->sam, 'admin');
});

function approvalErrors(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

test('approving a trial creates the tenant exactly as confirmed and converts the lead', function () {
    $lead = $this->makeLead(details: $this->leadDetails(shops: 2, tills: 3));

    $company = app(ApproveTrial::class)->handle($lead, $this->trialSetup([['Leeds', 'LDS', 2], ['Headingley', 'HDG', 1]]));

    expect($company->status)->toBe(CompanyStatus::Trial)
        ->and($company->trial_ends_at)->toBeNull()
        ->and($company->plan_id)->toBe($this->plan->id)
        ->and($company->name)->toBe('Patel News & Booze')
        ->and($company->email)->toBe('imran@patelnews.test')
        ->and($company->contact_name)->toBe('Imran Patel')
        ->and($company->address)->toBe('Leeds, LS6 2AB')
        ->and($company->notes)->toContain('From a trial request received on');

    $branches = Branch::withoutCompanyScope()->whereBelongsTo($company)->orderBy('code')->get();
    expect($branches->pluck('code')->all())->toBe(['HDG', 'LDS'])
        ->and($branches->pluck('name')->all())->toBe(['Headingley', 'Leeds']);

    $tills = fn (string $code) => Register::withoutCompanyScope()->where('branch_id', $branches->firstWhere('code', $code)->id)->orderBy('code')->get();
    expect($tills('LDS')->pluck('code')->all())->toBe(['01', '02'])
        ->and($tills('LDS')->firstWhere('code', '01')->is_main_till)->toBeTrue()
        ->and($tills('HDG')->pluck('code')->all())->toBe(['01'])
        ->and($tills('HDG')->first()->is_main_till)->toBeTrue();

    $licences = Licence::withoutCompanyScope()->whereBelongsTo($company)->get();
    expect($licences)->toHaveCount(3)
        ->and($licences->pluck('plan_id')->unique()->all())->toBe([$this->plan->id])
        ->and($licences->pluck('status')->unique()->all())->toBe([LicenceStatus::Issued]);

    $owner = $this->ownerOf($company);
    expect($owner->email)->toBe('imran@patelnews.test')->and($owner->name)->toBe('Imran Patel');

    $lead->refresh();
    expect($lead->status)->toBe(LeadStatus::Converted)
        ->and($lead->company_id)->toBe($company->id)
        ->and($lead->converted_at)->not->toBeNull()
        ->and($lead->converted_by_admin_id)->toBe($this->sam->id)
        ->and($lead->company->is($company))->toBeTrue();
});

test('the owner gets the welcome email with every key and the 7-day set-password email', function () {
    $lead = $this->makeLead(details: $this->leadDetails(shops: 2, tills: 3));

    $company = app(ApproveTrial::class)->handle($lead, $this->trialSetup([['Leeds', 'LDS', 2], ['Headingley', 'HDG', 1]]));

    Mail::assertQueued(SetPasswordMail::class, fn (SetPasswordMail $mail) => $mail->hasTo('imran@patelnews.test'));
    Mail::assertQueued(WelcomeTenantMail::class, 1);
    Mail::assertQueued(WelcomeTenantMail::class, function (WelcomeTenantMail $mail) use ($company) {
        $hashes = Licence::withoutCompanyScope()->whereBelongsTo($company)->pluck('key_hash')->all();
        $matched = array_filter($mail->data->tills, fn ($till) => in_array(LicenceKey::parse($till->licenceKey)->hash(), $hashes, true));

        return $mail->hasTo('imran@patelnews.test')
            && count($mail->data->tills) === 3
            && count($matched) === 3
            && collect($mail->data->tills)->pluck('branchName')->unique()->sort()->values()->all() === ['Headingley', 'Leeds']
            && $mail->data->trialDays === 7
            && $mail->data->companyId === $company->id;
    });
});

test('a contact who already has a portal login becomes owner, keeps their password and gets no set-password email', function () {
    User::factory()->create(['email' => 'imran@patelnews.test', 'password' => Hash::make('their-own')]);
    $lead = $this->makeLead();

    $company = app(ApproveTrial::class)->handle($lead, $this->trialSetup());

    expect(Hash::check('their-own', $this->ownerOf($company)->password))->toBeTrue();
    Mail::assertNotQueued(SetPasswordMail::class);
    Mail::assertQueued(WelcomeTenantMail::class, 1);
});

test('approval is audited on the lead and shows on the new tenant’s activity', function () {
    $lead = $this->makeLead();

    $company = app(ApproveTrial::class)->handle($lead, $this->trialSetup([['Leeds', 'LDS', 2]]));

    $audit = AuditLog::query()->where('action', 'lead.converted')->sole();
    expect($audit->subject_id)->toBe($lead->id)
        ->and($audit->actor_id)->toBe($this->sam->id)
        ->and($audit->company_id)->toBe($company->id)
        ->and($audit->before)->toBe(['status' => 'new'])
        ->and($audit->after)->toBe(['status' => 'converted', 'company_id' => $company->id])
        ->and($audit->meta)->toMatchArray(['shops' => 1, 'tills' => 2, 'plan' => 'standard']);

    expect(AuditLog::query()->where('action', 'company.created')->where('company_id', $company->id)->exists())->toBeTrue();

    $description = (new TenantActivity(collect([$audit])))->row($audit)['description'];
    expect($description)->toBe('Approved the trial request: created from a lead with 1 shop and 2 tills');

    $note = LeadNote::query()->where('lead_id', $lead->id)->where('kind', LeadNoteKind::StatusChanged)->sole();
    expect($note->body)->toBe('Approved a 7-day trial: created Patel News & Booze with 1 shop and 2 tills on Standard')
        ->and($note->meta)->toMatchArray(['from' => 'new', 'to' => 'converted', 'company_id' => $company->id])
        ->and($note->admin_id)->toBe($this->sam->id);
});

test('a lead can only be approved once', function () {
    $lead = $this->makeLead();
    $company = app(ApproveTrial::class)->handle($lead, $this->trialSetup());

    // The same model instance, a stale copy loaded before approval, and a fresh one all refuse.
    $stale = Lead::query()->find($lead->id);
    $stale->forceFill(['status' => LeadStatus::New, 'company_id' => null]);

    foreach ([$lead, $stale, $lead->fresh()] as $attempt) {
        $errors = approvalErrors(fn () => app(ApproveTrial::class)->handle($attempt, $this->trialSetup([['Leeds', 'LDX', 1]])));
        expect($errors)->toHaveKey('status');
    }

    expect(Company::query()->count())->toBe(1)
        ->and($lead->fresh()->company_id)->toBe($company->id);
    Mail::assertQueued(WelcomeTenantMail::class, 1);
});

test('only open leads with an email can be approved', function () {
    $rejected = $this->makeLead();
    app(RejectLead::class)->handle($rejected, 'Not a shop');
    $noEmail = $this->makeLead(details: $this->leadDetails('Phone Only', null, '07700 900999'));
    $archived = $this->makeLead(details: $this->leadDetails('Archived', 'arch@test.test'));
    $archived->delete();

    expect(approvalErrors(fn () => app(ApproveTrial::class)->handle($rejected, $this->trialSetup())))->toHaveKey('status')
        ->and(approvalErrors(fn () => app(ApproveTrial::class)->handle($noEmail, $this->trialSetup())))->toHaveKey('email')
        ->and(approvalErrors(fn () => app(ApproveTrial::class)->handle($archived, $this->trialSetup())))->toHaveKey('status')
        ->and(Company::query()->count())->toBe(0);
});

test('shop names, codes and tills are checked before anything is created', function (array $shops, string $field) {
    $lead = $this->makeLead();

    expect(approvalErrors(fn () => app(ApproveTrial::class)->handle($lead, $this->trialSetup($shops))))->toHaveKey($field)
        ->and(Company::query()->count())->toBe(0)
        ->and($lead->fresh()->status)->toBe(LeadStatus::New);
})->with([
    'duplicate code' => [[['Leeds', 'LDS', 1], ['Leeds 2', 'LDS', 1]], 'shops.1.code'],
    'bad code' => [[['Leeds', 'L1', 1]], 'shops.0.code'],
    'no name' => [[[' ', 'LDS', 1]], 'shops.0.name'],
    'no tills' => [[['Leeds', 'LDS', 0]], 'shops.0.tills'],
    'too many tills' => [[['Leeds', 'LDS', 21]], 'shops.0.tills'],
    'no shops' => [[], 'shops'],
]);

test('a chosen plan is used; without any active plan approval is refused', function () {
    $pro = $this->proPlan();
    $lead = $this->makeLead();

    $company = app(ApproveTrial::class)->handle($lead, $this->trialSetup(planId: $pro->id));
    expect($company->plan_id)->toBe($pro->id)
        ->and(Licence::withoutCompanyScope()->whereBelongsTo($company)->pluck('plan_id')->unique()->all())->toBe([$pro->id]);

    $other = $this->makeLead(details: $this->leadDetails('Other Shop', 'other@shop.test', '07700 900222'));
    $pro->update(['is_active' => false]);
    $this->plan->update(['is_active' => false]);

    expect(approvalErrors(fn () => app(ApproveTrial::class)->handle($other, $this->trialSetup())))->toHaveKey('plan_id')
        ->and(approvalErrors(fn () => app(ApproveTrial::class)->handle($other, $this->trialSetup(planId: $pro->id))))->toHaveKey('plan_id');
});

test('the suggestion has one branch per shop, tills spread across them, unique codes and the default plan', function () {
    $lead = $this->makeLead(details: $this->leadDetails(shops: 3, tills: 5, town: 'Leeds'));

    $setup = TrialSuggestion::for($lead)->toArray();

    expect($setup['planId'])->toBe($this->plan->id)
        ->and(array_column($setup['shops'], 'name'))->toBe(['Leeds', 'Shop 2', 'Shop 3'])
        ->and(array_column($setup['shops'], 'tills'))->toBe([2, 2, 1])
        ->and(array_column($setup['shops'], 'code'))->toBe(['LDS', 'SHP', 'SHA']);

    $fewTills = $this->makeLead(details: $this->leadDetails('Two Shops', 'two@shops.test', null, shops: 2, tills: 1, town: null));
    expect(array_column(TrialSuggestion::for($fewTills)->toArray()['shops'], 'tills'))->toBe([1, 1])
        ->and(array_column(TrialSuggestion::for($fewTills)->toArray()['shops'], 'name'))->toBe(['Shop 1', 'Shop 2']);

    $single = $this->makeLead(details: $this->leadDetails('Khan Mini Mart', 'khan@mart.test', null, town: null));
    expect(TrialSuggestion::for($single)->toArray()['shops'][0])->toMatchArray(['name' => 'Khan Mini Mart', 'code' => 'KMM', 'tills' => 2]);
});

test('the suggested setup can be approved as is', function () {
    $lead = $this->makeLead(details: $this->leadDetails(shops: 3, tills: 5));

    $company = app(ApproveTrial::class)->handle($lead, TrialSuggestion::for($lead));

    expect(Register::withoutCompanyScope()->whereBelongsTo($company)->count())->toBe(5)
        ->and(Branch::withoutCompanyScope()->whereBelongsTo($company)->count())->toBe(3)
        ->and($company->users()->wherePivot('role', CompanyRole::Owner->value)->count())->toBe(1);
});
