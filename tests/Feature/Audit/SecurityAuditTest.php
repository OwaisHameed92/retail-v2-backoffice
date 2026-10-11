<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Mail\Actions\UpdateEmailSettings;
use App\Domain\Mail\Data\CustomerStatementData;
use App\Domain\Mail\Data\PortalInvitationData;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Mailables\CustomerStatementMail;
use App\Domain\Mail\Mailables\PortalInvitationMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Models\HeldEmail;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Security audit 2026-10-11 (docs/audit/2026-10-11-security.md). Each test states the SECURE behaviour, so it FAILS
 * on main while the finding is open and passes once it is fixed. Group `audit-gap`: run with
 * `php artisan test --group=audit-gap`; exclude with `--exclude-group=audit-gap` until the fixes land.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    config(['mail.default' => 'array']);
});

function auditMember(CompanyRole $role = CompanyRole::Owner): User
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['email' => 'owner@audit.test']);
    $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => true]);

    return $user;
}

test('SEC-1: with two-factor switched off (production setting) an admin still needs a second factor', function () {
    config(['security.two_factor.enabled' => false]); // .env.production.example: TWO_FACTOR_ENABLED=false
    $admin = Admin::factory()->role(AdminRole::Owner)->create();

    $this->withoutTwoFactorPass()->actingAs($admin, 'admin')->get('/admin')
        ->assertRedirect(route('admin.two-factor.setup'));
})->group('audit-gap');

test('SEC-2: an Accounts admin (no licences.manage) cannot read full licence keys in a held welcome email preview', function () {
    $this->atLondon('2026-10-01 10:00');
    app(UpdateEmailSettings::class)->handle(array_fill_keys(EmailCategory::values(), false));
    $this->licensedTenant('Khan Mini Mart', 1);
    $held = HeldEmail::query()->where('template', WelcomeTenantMail::templateKey())->sole();
    /** @var WelcomeTenantMail $mail */
    $mail = $held->mailable();
    $fullKey = $mail->data->tills[0]->licenceKey;

    expect(strlen($fullKey))->toBeGreaterThan(10);

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')->get(route('admin.emails.held.preview', $held->id))
        ->assertOk()->assertDontSee($fullKey);
})->group('audit-gap');

test('SEC-3: tenant-typed Markdown in the invitation email (sent to any address) is not a clickable link', function () {
    $mail = new PortalInvitationMail(new PortalInvitationData(
        name: '[Bilal](https://evil.test/b)',
        email: 'stranger@example.test',
        businessName: '[Verify your account](https://evil.test/login)',
        roleLabel: 'Manager',
        branchName: null,
        inviterName: '![](https://evil.test/pixel.gif)',
        url: config('sspos.portal_url').'/app/invitations/01J00000000000000000000000/token',
        expiresAt: Carbon::now()->addDays(7),
    ));

    expect($mail->render())->not->toContain('href="https://evil.test')->not->toContain('src="https://evil.test');
})->group('audit-gap');

test('SEC-3: tenant-typed Markdown in the customer statement email is not a clickable link', function () {
    $mail = new CustomerStatementMail(new CustomerStatementData(
        businessName: '[Pay here](https://evil.test/pay)',
        businessEmail: 'shop@example.test',
        businessPhone: '[0113](https://evil.test/call)',
        customerName: 'Aisha Rahman',
        period: '1 Oct – 31 Oct 2026',
        closingBalance: '42.50',
        closingPoints: 0,
    ));

    expect($mail->render())->not->toContain('href="https://evil.test');
})->group('audit-gap');

test('SEC-4: a Support admin (no billing.manage) cannot change a business\'s plan and its licences\' plan', function () {
    $company = $this->licensedTenant(tills: 2);

    $this->actingAs($this->admin(AdminRole::Support), 'admin')
        ->put("/admin/tenants/{$company->id}/plan", ['plan_id' => $this->proPlan()->id, 'apply_to_licences' => true])
        ->assertForbidden();
})->group('audit-gap');

test('SEC-5: changing the account email needs the current password', function () {
    $user = auditMember();

    $this->actingAs($user)->patch('/settings/profile', ['name' => $user->name, 'email' => 'attacker@evil.test'])
        ->assertSessionHasErrors();
    expect($user->fresh()->email)->toBe('owner@audit.test');
})->group('audit-gap');

test('SEC-5: a changed, unverified email does not keep full portal access ("must be verified again")', function () {
    $user = auditMember();

    $this->actingAs($user)->patch('/settings/profile', ['name' => $user->name, 'email' => 'attacker@evil.test']);
    expect($user->fresh()->email_verified_at)->toBeNull();

    $this->actingAs($user->fresh())->get('/app')->assertRedirect(route('verification.notice'));
})->group('audit-gap');

test('SEC-6: changing your password ends "remember me" on other devices', function () {
    $user = auditMember();
    $user->forceFill(['remember_token' => 'stolen-remember-token'])->save();

    $this->actingAs($user)->put('/settings/password', [
        'current_password' => 'password',
        'password' => 'A-new-long-passphrase-2026',
        'password_confirmation' => 'A-new-long-passphrase-2026',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->remember_token)->not->toBe('stolen-remember-token');
})->group('audit-gap');

test('SEC-7: one IP trying many accounts (password spraying) is slowed down', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->post('/login', ['email' => "user{$i}@audit.test", 'password' => 'Winter2026!']);
    }

    $this->post('/login', ['email' => 'user99@audit.test', 'password' => 'Winter2026!'])->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toStartWith('Too many login attempts');
})->group('audit-gap');

test('SEC-8: supplier invoice and credit note lists never show another business\'s delivery or invoice number', function () {
    $this->travelTo('2026-09-30 10:00:00');
    $sync = new SyncApiFixtures($this);
    F::catalogue($sync->company);
    $owner = F::member($sync->company, CompanyRole::Owner);
    $other = Company::factory()->create();
    $theirShop = Branch::factory()->forCompany($other)->create();
    $theirGrn = F::row('goods_receipts', $other, $theirShop, ['supplier_id' => F::SUPPLIER, 'supplier_name' => 'Booker', 'delivery_note_number' => 'DN-SECRET',
        'received_date' => '2026-09-28', 'status' => 'posted', 'net_amount' => '1.00', 'vat_amount' => '0.00', 'gross_amount' => '1.00']);
    $theirInvoice = F::invoice($other, $theirShop, '2026-09-27', '9.99', ['invoice_number' => 'INV-SECRET']);

    // A till of this business names the other business's ids (till-supplied, not checked as parents).
    F::invoice($sync->company, $sync->leeds, '2026-09-28', '23.52', ['goods_receipt_id' => $theirGrn]);
    F::credit($sync->company, $sync->leeds, '2026-09-29', '1.96', ['linked_invoice_id' => $theirInvoice]);

    $this->actingAs($owner)->get('/app/purchasing/invoices')->assertOk()->assertDontSee('DN-SECRET');
    $this->actingAs($owner)->get('/app/purchasing/credit-notes')->assertOk()->assertDontSee('INV-SECRET');
})->group('audit-gap');
