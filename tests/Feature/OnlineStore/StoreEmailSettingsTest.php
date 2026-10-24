<?php

use App\Domain\OnlineStore\Mail\OnlineOrderReceivedMail;
use App\Domain\OnlineStore\Mail\Sending\SmtpHostGuard;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreMailSettings;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\OnlineStore\StoreMailFakes;
use Tests\Feature\Purchasing\PurchasingFixtures as F;

/*
 * OS-10: the online store's Emails tab: access, sender name / Reply-To / own SMTP server validation, the SSRF guard,
 * the password (encrypted, never sent to the browser, blank keeps it), the test email and the previews, and that one
 * business never sees or changes another's settings.
 */

beforeEach(function () {
    $this->withoutVite();
    StoreMailFakes::dns();
    $this->f = OnlineStoreFixtures::make();
    $this->company = $this->f->company;
    $this->owner = F::member($this->company, CompanyRole::Owner);
    $this->manager = F::member($this->company, CompanyRole::Manager);
    $this->server = fn (array $overrides = []) => [
        'sender_name' => 'Kirkgate Butchers', 'reply_to' => 'help@kirkgate.co.uk', 'smtp_enabled' => true,
        'smtp_host' => 'smtp.kirkgate.co.uk', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => 'shop@kirkgate.co.uk',
        'smtp_password' => 's3cret-Pa55', 'smtp_from_address' => 'orders@kirkgate.co.uk', ...$overrides,
    ];
    $this->settings = fn () => OnlineStoreMailSettings::withoutCompanyScope()->where('company_id', $this->company->id)->first();
});

afterEach(fn () => SmtpHostGuard::resolveUsing(null));

test('owners and managers open the Emails tab; staff and accountants get 403; guests log in; a one-shop user only looks', function () {
    $urls = [['get', '/app/online-store/emails', []], ['put', '/app/online-store/emails', ($this->server)()],
        ['post', '/app/online-store/emails/test', []], ['get', '/app/online-store/emails/preview/online-order-received', []]];

    foreach ($urls as [$method, $url, $body]) {
        $this->{$method}($url, $body)->assertRedirect('/login');
    }

    foreach ([CompanyRole::Staff, CompanyRole::Accountant] as $role) {
        $user = F::member($this->company, $role);
        foreach ($urls as [$method, $url, $body]) {
            $this->actingAs($user)->{$method.'Json'}($url, $body)->assertForbidden();
        }
    }

    foreach ([$this->owner, $this->manager] as $user) {
        $this->actingAs($user)->get('/app/online-store/emails')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('app/online-store/emails')->where('canEdit', true)->where('store.businessName', 'Kirkgate Convenience'));
    }

    $oneShop = F::member($this->company, CompanyRole::Manager, $this->f->leeds);
    $this->actingAs($oneShop)->get('/app/online-store/emails')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canEdit', false));
    $this->actingAs($oneShop)->putJson('/app/online-store/emails', ($this->server)())->assertForbidden();
    $this->actingAs($oneShop)->postJson('/app/online-store/emails/test')->assertForbidden();
    expect(($this->settings)())->toBeNull();
});

test('every Emails route answers 404 while the online store is switched off', function () {
    config(['online_store.enabled' => false]);

    $this->actingAs($this->owner)->get('/app/online-store/emails')->assertNotFound();
    $this->actingAs($this->owner)->putJson('/app/online-store/emails', ($this->server)())->assertNotFound();
    $this->actingAs($this->owner)->postJson('/app/online-store/emails/test')->assertNotFound();
});

test('saving stores the settings with the password encrypted; the page never gets the password, only its last 4', function () {
    $this->actingAs($this->owner)->put('/app/online-store/emails', ($this->server)())->assertRedirect('/app/online-store/emails')->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. Send a test email to start using your own email server.');

    $settings = ($this->settings)();
    $raw = DB::table('online_store_mail_settings')->where('id', $settings->id)->value('smtp_password');
    expect($settings->smtp_password)->toBe('s3cret-Pa55')->and($settings->smtp_password_last4)->toBe('Pa55')
        ->and($raw)->not->toBe('s3cret-Pa55')->not->toContain('s3cret')->and(decrypt($raw, false))->toBe('s3cret-Pa55')
        ->and($settings->smtp_verified_at)->toBeNull()->and($settings->toArray())->not->toHaveKey('smtp_password')
        ->and(json_encode(AuditLog::query()->where('action', 'online_store.emails_saved')->sole()->toArray()))->not->toContain('s3cret');

    $response = $this->actingAs($this->owner)->get('/app/online-store/emails');
    expect($response->getContent())->not->toContain('s3cret');
    $response->assertInertia(fn (Assert $p) => $p->where('store.smtp.passwordSaved', true)->where('store.smtp.passwordLast4', 'Pa55')
        ->where('store.smtp.host', 'smtp.kirkgate.co.uk')->where('store.status.mode', 'needsTest')->missing('store.smtp.password'));
});

test('a blank password keeps the saved one; changing the server clears verified; off keeps the details; remove forgets them', function () {
    $this->actingAs($this->owner)->put('/app/online-store/emails', ($this->server)());
    ($this->settings)()->forceFill(['smtp_verified_at' => now()])->save();

    $this->actingAs($this->owner)->put('/app/online-store/emails', ($this->server)(['smtp_password' => '', 'sender_name' => 'Kirkgate Meats']))->assertSessionHasNoErrors();
    expect(($this->settings)())->smtp_password->toBe('s3cret-Pa55')->sender_name->toBe('Kirkgate Meats')->smtp_verified_at->not->toBeNull();

    $this->actingAs($this->owner)->put('/app/online-store/emails', ($this->server)(['smtp_password' => '', 'smtp_host' => 'mail.kirkgate.co.uk']))->assertSessionHasNoErrors();
    expect(($this->settings)())->smtp_password->toBe('s3cret-Pa55')->smtp_verified_at->toBeNull();

    $this->actingAs($this->owner)->put('/app/online-store/emails', ['sender_name' => '', 'reply_to' => '', 'smtp_enabled' => false])->assertSessionHasNoErrors();
    expect(($this->settings)())->smtp_enabled->toBeFalse()->smtp_host->toBe('mail.kirkgate.co.uk')->smtp_password->toBe('s3cret-Pa55');

    $this->actingAs($this->owner)->put('/app/online-store/emails', ['smtp_enabled' => false, 'smtp_forget' => true])->assertSessionHasNoErrors();
    expect(($this->settings)())->smtp_host->toBeNull()->smtp_password->toBeNull()->smtp_password_last4->toBeNull()->smtp_username->toBeNull();
});

test('the settings are validated in plain words', function (array $input, string $field) {
    $this->actingAs($this->owner)->from('/app/online-store/emails')->put('/app/online-store/emails', ($this->server)($input))
        ->assertRedirect('/app/online-store/emails')->assertSessionHasErrors($field)->assertSessionMissing('_old_input.smtp_password');
    expect(($this->settings)())->toBeNull();
})->with([
    'sender name in the platform\'s name' => [['sender_name' => 'Switch & Save'], 'sender_name'],
    'sender name "switch and save"' => [['sender_name' => 'Orders from SWITCH AND SAVE'], 'sender_name'],
    'sender name with a new line' => [['sender_name' => "Kirkgate\r\nBcc: x@y.z"], 'sender_name'],
    'sender name too long' => [['sender_name' => str_repeat('a', 71)], 'sender_name'],
    'bad reply-to' => [['reply_to' => 'not an email'], 'reply_to'],
    'missing host' => [['smtp_host' => ''], 'smtp_host'],
    'odd port' => [['smtp_port' => 8080], 'smtp_port'],
    'port 22' => [['smtp_port' => 22], 'smtp_port'],
    '465 without SSL' => [['smtp_port' => 465, 'smtp_encryption' => 'tls'], 'smtp_encryption'],
    'SSL on 587' => [['smtp_port' => 587, 'smtp_encryption' => 'ssl'], 'smtp_encryption'],
    'unknown encryption' => [['smtp_encryption' => 'starttls-maybe'], 'smtp_encryption'],
    'missing username' => [['smtp_username' => ''], 'smtp_username'],
    'no password saved' => [['smtp_password' => ''], 'smtp_password'],
    'bad from address' => [['smtp_from_address' => 'orders'], 'smtp_from_address'],
]);

test('the SSRF guard refuses local, internal, private and metadata hosts', function (string $host) {
    $this->actingAs($this->owner)->put('/app/online-store/emails', ($this->server)(['smtp_host' => $host]))->assertSessionHasErrors('smtp_host');
    expect(($this->settings)())->toBeNull();
})->with(['localhost', '127.0.0.1', '10.1.2.3', '192.168.1.10', '172.16.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fd00::1',
    'smtp', 'mail.internal', 'smtp.shop.local', 'printer.localhost', 'smtp.internal-corp.co.uk', 'metadata.cloud.co.uk', 'smtp.nowhere.co.uk',
    'smtp.kirkgate.co.uk:25', 'http://smtp.kirkgate.co.uk', 'smtp kirkgate.co.uk']);

test('the guard accepts public hosts and checks every resolved address', function () {
    expect(SmtpHostGuard::problem('smtp.kirkgate.co.uk'))->toBeNull()
        ->and(SmtpHostGuard::problem('203.0.113.25'))->toBeNull()
        ->and(SmtpHostGuard::publicIp('::ffff:127.0.0.1'))->toBeFalse()
        ->and(SmtpHostGuard::publicIp('8.8.8.8'))->toBeTrue();

    SmtpHostGuard::resolveUsing(fn () => ['203.0.113.10', '10.0.0.1']);
    expect(SmtpHostGuard::problem('smtp.kirkgate.co.uk'))->not->toBeNull();
});

test('a business only ever reads and writes its own email settings', function () {
    $other = OnlineStoreFixtures::make('Other Co', 'other-co');
    $otherOwner = F::member($other->company, CompanyRole::Owner);
    $this->actingAs($otherOwner)->put('/app/online-store/emails', ($this->server)(['smtp_host' => 'smtp.other.co.uk', 'sender_name' => 'Other Shop']));

    $this->actingAs($this->owner)->get('/app/online-store/emails')->assertInertia(fn (Assert $p) => $p->where('store.businessName', 'Kirkgate Convenience')
        ->where('store.smtp.host', null)->where('store.senderName', null));
    $this->actingAs($this->owner)->put('/app/online-store/emails', ($this->server)());

    $rows = OnlineStoreMailSettings::withoutCompanyScope()->orderBy('company_id')->get()->keyBy('company_id');
    expect($rows)->toHaveCount(2)
        ->and($rows[$this->company->id]->online_store_id)->toBe($this->f->store->id)->and($rows[$this->company->id]->smtp_host)->toBe('smtp.kirkgate.co.uk')
        ->and($rows[$other->company->id]->smtp_host)->toBe('smtp.other.co.uk')->and($rows[$other->company->id]->sender_name)->toBe('Other Shop');

    $this->actingAs($this->owner)->get('/app/online-store/emails/preview/online-order-received')->assertOk()
        ->assertSee('Kirkgate Butchers')->assertDontSee('Other Shop');
});

test('a business without a store gets 404 on the Emails tab and saves nothing', function () {
    OnlineStore::withoutCompanyScope()->where('company_id', $this->company->id)->delete();

    $this->actingAs($this->owner)->get('/app/online-store/emails')->assertNotFound();
    $this->actingAs($this->owner)->putJson('/app/online-store/emails', ($this->server)())->assertNotFound();
    $this->actingAs($this->owner)->get('/app/online-store/emails/preview/online-order-received')->assertNotFound();
    expect(OnlineStoreMailSettings::withoutCompanyScope()->count())->toBe(0);
});

test('a test email through the store\'s own server marks it verified; to the signed-in user only', function () {
    $fake = StoreMailFakes::smtp();
    $this->actingAs($this->owner)->put('/app/online-store/emails', ($this->server)());

    $this->actingAs($this->owner)->post('/app/online-store/emails/test', ['to' => 'someone@else.test'])->assertRedirect('/app/online-store/emails')
        ->assertSessionHas('success', "Test email sent to {$this->owner->email} through smtp.kirkgate.co.uk. Customer emails now go from your own email server.");

    $messages = $fake->transport->messages();
    expect($messages)->toHaveCount(1);
    $email = $messages->first()->getOriginalMessage();
    expect($email->getTo()[0]->getAddress())->toBe($this->owner->email)
        ->and($email->getFrom()[0]->getAddress())->toBe('orders@kirkgate.co.uk')->and($email->getFrom()[0]->getName())->toBe('Kirkgate Butchers')
        ->and($email->getReplyTo()[0]->getAddress())->toBe('help@kirkgate.co.uk')
        ->and($email->getSubject())->toBe("[Test] We've got your order W-0042")
        ->and(($this->settings)())->smtp_verified_at->not->toBeNull()->last_error->toBeNull()->last_test_at->not->toBeNull();
    $this->actingAs($this->owner)->get('/app/online-store/emails')->assertInertia(fn (Assert $p) => $p->where('store.status.mode', 'ownServer'));
});

test('a failed test email reports the reason in plain words, without the password, and clears verified', function () {
    StoreMailFakes::smtp(StoreMailFakes::authFailure());
    $this->actingAs($this->owner)->put('/app/online-store/emails', ($this->server)());
    ($this->settings)()->forceFill(['smtp_verified_at' => now()])->save();

    $response = $this->actingAs($this->owner)->post('/app/online-store/emails/test');
    $response->assertSessionHas('error', 'The test email did not go: Your email server did not accept the username or password. Check them, or create an app password if your provider asks for one.');

    $settings = ($this->settings)();
    expect($settings->smtp_verified_at)->toBeNull()->and($settings->last_error)->not->toContain('s3cret')->and($settings->last_error_at)->not->toBeNull();
    $this->actingAs($this->owner)->get('/app/online-store/emails')->assertInertia(fn (Assert $p) => $p->where('store.status.mode', 'needsTest')
        ->where('store.status.lastError', $settings->last_error));
});

test('a server error the guard does not know is quoted with the password removed', function () {
    StoreMailFakes::smtp(new TransportException('Expected response code "250" but got code "451", with message "451 Temporary local problem s3cret-Pa55".'));
    $this->actingAs($this->owner)->put('/app/online-store/emails', ($this->server)());

    $this->actingAs($this->owner)->post('/app/online-store/emails/test')
        ->assertSessionHas('error', 'The test email did not go: Your email server said: 451 Temporary local problem ••••');
});

test('without an own server the test email is queued through the platform in the shop\'s name', function () {
    Mail::fake();

    $this->actingAs($this->owner)->post('/app/online-store/emails/test')->assertSessionHas('success', "Test email on its way to {$this->owner->email}, in your shop's name.");

    Mail::assertQueued(OnlineOrderReceivedMail::class, fn (OnlineOrderReceivedMail $mail) => $mail->hasTo($this->owner->email) && $mail->isTest
        && $mail->hasFrom((string) config('mail.from.address'), 'Kirkgate Convenience') && $mail->data->companyId === $this->company->id);
    expect(AuditLog::query()->where('action', 'online_store.test_email_sent')->count())->toBe(1);
});

test('the test email is rate limited', function () {
    Mail::fake();

    foreach (range(1, 5) as $i) {
        $this->actingAs($this->owner)->post('/app/online-store/emails/test')->assertRedirect();
    }

    $this->actingAs($this->owner)->post('/app/online-store/emails/test')->assertStatus(429);
});

test('each customer email previews in the store\'s look, HTML and plain text; unknown or staff templates are 404', function (string $key) {
    $this->actingAs($this->owner)->put('/app/online-store/emails', ($this->server)());
    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['brand_colour' => '#8F1D1D']);

    $html = $this->actingAs($this->owner)->get("/app/online-store/emails/preview/{$key}")->assertOk()
        ->assertHeader('Content-Security-Policy')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->getContent();
    expect($html)->toContain('Kirkgate Butchers')->toContain('#8F1D1D')->toContain('Online store by Switch &amp; Save')->not->toContain('s3cret');

    $this->actingAs($this->owner)->get("/app/online-store/emails/preview/{$key}?format=text")->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSee('Kirkgate Butchers');
})->with(['online-order-received', 'online-order-ready', 'online-order-cancelled']);

test('the staff email and unknown templates have no store preview', function () {
    $this->actingAs($this->owner)->get('/app/online-store/emails/preview/online-order-new')->assertNotFound();
    $this->actingAs($this->owner)->get('/app/online-store/emails/preview/welcome-tenant')->assertNotFound();
    $this->actingAs($this->owner)->get('/app/online-store/emails')->assertInertia(fn (Assert $p) => $p->where('templates', fn ($t) => collect($t)->pluck('key')->all() === ['online-order-received', 'online-order-ready', 'online-order-cancelled']));
});
