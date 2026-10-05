<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Mailables\AdminNewLeadMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Mail\Support\EmailTemplates;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

function emailLogRow(array $attributes = []): EmailLog
{
    $log = EmailLog::query()->create(array_merge([
        'to' => 'aisha@example.test',
        'mailable' => WelcomeTenantMail::class,
        'template' => 'welcome-tenant',
        'subject' => 'Welcome to Switch & Save – your licence keys',
        'status' => EmailStatus::Sent,
        'sent_at' => now(),
        'message_id' => 'abc@example.test',
        'meta' => ['business' => 'Khan Mini Mart', 'tills' => 2],
    ], $attributes));

    if (isset($attributes['created_at'])) {
        $log->forceFill(['created_at' => $attributes['created_at']])->save();
    }

    return $log;
}

dataset('email routes', [
    'log' => ['get', '/admin/emails'],
    'templates' => ['get', '/admin/emails/templates'],
    'preview' => ['get', '/admin/emails/templates/welcome-tenant/preview'],
    'send test' => ['post', '/admin/emails/templates/welcome-tenant/test'],
]);

it('sends guests to the admin login', function (string $method, string $uri) {
    $this->{$method}($uri)->assertRedirect('/admin/login');
})->with('email routes');

it('keeps tenant users out', function (string $method, string $uri) {
    $this->actingAs(User::factory()->create())->{$method}($uri)->assertRedirect('/admin/login');
})->with('email routes');

it('forbids sales and accounts staff', function (AdminRole $role, string $method, string $uri) {
    Mail::fake();
    $this->actingAs(Admin::factory()->role($role)->create(), 'admin')->{$method}($uri)->assertForbidden();
    Mail::assertNothingQueued();
})->with([AdminRole::Sales, AdminRole::Accounts])->with('email routes');

it('forbids inactive admins', function () {
    $this->actingAs(Admin::factory()->owner()->inactive()->create(), 'admin')->get('/admin/emails')->assertRedirect('/admin/login');
});

it('lets owner and support staff open the log', function (AdminRole $role) {
    $company = Company::factory()->create(['name' => 'Khan Mini Mart']);
    emailLogRow(['company_id' => $company->id]);

    $this->actingAs(Admin::factory()->role($role)->create(), 'admin')
        ->get('/admin/emails')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/emails/index')
            ->has('logs.data', 1)
            ->where('logs.meta.total', 1)
            ->where('logs.data.0.to', 'aisha@example.test')
            ->where('logs.data.0.templateLabel', 'Welcome and licence keys')
            ->where('logs.data.0.company.name', 'Khan Mini Mart')
            ->where('logs.data.0.status', 'sent')
            ->where('logs.data.0.meta.business', 'Khan Mini Mart')
            ->where('summary.sent', 1)
            ->has('templateOptions', count(EmailTemplates::MAILABLES))
            ->has('statusOptions', 4)); // queued, sent, failed, suppressed (demo)
})->with([AdminRole::Owner, AdminRole::Support]);

it('searches and filters the log by template, status and date', function () {
    $owner = Admin::factory()->owner()->create();
    emailLogRow(['to' => 'aisha@example.test', 'created_at' => now()->subDays(10)]);
    emailLogRow(['to' => 'bilal@example.test', 'template' => 'trial-ended', 'subject' => 'Your Switch & Save free trial has ended']);
    emailLogRow(['to' => 'chloe@example.test', 'status' => EmailStatus::Failed, 'error' => 'Timed out', 'sent_at' => null]);

    $total = fn (string $query) => $this->actingAs($owner, 'admin')->get('/admin/emails'.$query)->viewData('page')['props']['logs']['meta']['total'];

    expect($total(''))->toBe(3)
        ->and($total('?search=bilal'))->toBe(1)
        ->and($total('?search=free%20trial'))->toBe(1)
        ->and($total('?template=trial-ended'))->toBe(1)
        ->and($total('?status=failed'))->toBe(1)
        ->and($total('?from='.now()->subDays(2)->toDateString()))->toBe(2)
        ->and($total('?to='.now()->subDays(5)->toDateString()))->toBe(1);

    $this->actingAs($owner, 'admin')->get('/admin/emails?status=bogus')->assertSessionHasErrors('status');
});

it('lists every template with a sample subject', function () {
    $this->actingAs(Admin::factory()->owner()->create(), 'admin')
        ->get('/admin/emails/templates?template=trial-reminder')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/emails/templates')
            ->has('templates', count(EmailTemplates::MAILABLES))
            ->where('selected', 'trial-reminder')
            ->where('templates.0.key', 'welcome-tenant')
            ->where('templates.'.array_search('admin-new-lead', EmailTemplates::keys(), true).'.audience', 'staff'));
});

it('falls back to the first template for an unknown key', function () {
    $this->actingAs(Admin::factory()->owner()->create(), 'admin')
        ->get('/admin/emails/templates?template=nope')
        ->assertInertia(fn (Assert $page) => $page->where('selected', 'welcome-tenant'));
});

it('previews every template as HTML and plain text without sending or logging', function (string $key) {
    Mail::fake();
    $admin = Admin::factory()->role(AdminRole::Support)->create();

    $html = $this->actingAs($admin, 'admin')->get("/admin/emails/templates/{$key}/preview")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy');

    expect($html->getContent())->toContain('<base target="_blank">')->toContain('switch-save-logo.png');

    $this->actingAs($admin, 'admin')->get("/admin/emails/templates/{$key}/preview?format=text")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Smart Solutions for Smart Businesses');

    Mail::assertNothingOutgoing();
    expect(EmailLog::query()->count())->toBe(0);
})->with(fn () => EmailTemplates::keys());

it('returns 404 for an unknown template preview or test', function () {
    $admin = Admin::factory()->owner()->create();

    $this->actingAs($admin, 'admin')->get('/admin/emails/templates/unknown/preview')->assertNotFound();
    $this->actingAs($admin, 'admin')->post('/admin/emails/templates/unknown/test')->assertNotFound();
});

it('sends a test only to the signed-in admin and audits it', function () {
    Mail::fake();
    $admin = Admin::factory()->role(AdminRole::Support)->create(['email' => 'sam@switchandsave.test']);

    $this->actingAs($admin, 'admin')
        ->post('/admin/emails/templates/admin-new-lead/test')
        ->assertRedirect('/admin/emails/templates?template=admin-new-lead')
        ->assertSessionHas('emailToast.message', 'Test of "New trial request (staff)" queued for sam@switchandsave.test.');

    Mail::assertQueued(AdminNewLeadMail::class, function (AdminNewLeadMail $mail) {
        return $mail->isTest
            && $mail->hasTo('sam@switchandsave.test')
            && ! $mail->hasTo((string) config('sspos.staff_email'))
            && $mail->envelope()->subject === '[Test] New trial request: Patel News & Booze';
    });

    $audit = AuditLog::query()->where('action', 'email.test_sent')->sole();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->meta)->toBeIgnoringKeyOrder(['template' => 'admin-new-lead', 'to' => 'sam@switchandsave.test']);
});

it('logs a test send as a test with the [Test] subject', function () {
    $admin = Admin::factory()->owner()->create(['email' => 'olivia@switchandsave.test']);

    $this->actingAs($admin, 'admin')->post('/admin/emails/templates/welcome-tenant/test')->assertRedirect();

    $log = EmailLog::query()->sole();
    expect($log->to)->toBe('olivia@switchandsave.test')
        ->and($log->subject)->toBe('[Test] Welcome to Switch & Save – your licence keys')
        ->and($log->status)->toBe(EmailStatus::Sent)
        ->and($log->meta['test'])->toBeTrue();
});

it('rate limits test sends', function () {
    Mail::fake();
    $admin = Admin::factory()->owner()->create();

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($admin, 'admin')->post('/admin/emails/templates/trial-ended/test')->assertRedirect();
    }

    $this->actingAs($admin, 'admin')->post('/admin/emails/templates/trial-ended/test')->assertStatus(429);
});
