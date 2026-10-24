<?php

use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\OnlineStore\Actions\ChangeOnlineOrderStatus;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Mail\OnlineOrderReceivedMail;
use App\Domain\OnlineStore\Mail\Sending\SmtpHostGuard;
use App\Domain\OnlineStore\Mail\StoreMailBrand;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreMailSettings;
use App\Domain\OnlineStore\Support\OnlineOrderMessages;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\OnlineStore\StoreMailFakes;
use Tests\Feature\OnlineStore\StoreTestRoutes;

/*
 * OS-10: which server sends a shop's customer emails (its own SMTP when set up and tested, else the platform in the
 * shop's name), the fallback when the shop's server fails, and the shop's branding in the rendered emails. The queue
 * runs synchronously and every transport is in memory: no real mail is sent.
 */

beforeEach(function () {
    StoreTestRoutes::ensure();
    StoreMailFakes::dns();
    config(['app.url' => 'https://portal.example.test', 'sspos.portal_url' => 'https://portal.example.test', 'mail.default' => 'array',
        'mail.from.address' => 'no-reply@switchandsave.test', 'mail.from.name' => 'Switch & Save']);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    C::member($this->f->company, CompanyRole::Owner);
    $this->platform = fn (): Collection => app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    $this->emails = fn (Collection $sent): Collection => $sent->map(fn (SentMessage $m) => $m->getOriginalMessage())->values();
    $this->place = fn () => $this->f->order([['productId' => $this->f->product('Beans'), 'quantity' => '2']], '2026-11-02T10:00:00Z')->order;
});

afterEach(fn () => SmtpHostGuard::resolveUsing(null));

function customerEmail(Collection $emails): ?Email
{
    return $emails->first(fn (Email $e) => $e->getTo()[0]->getAddress() === 'asha@example.test');
}

test('without an own server customer emails go through the platform in the shop\'s name, never as Switch & Save', function () {
    ($this->place)();

    $emails = ($this->emails)(($this->platform)());
    $customer = customerEmail($emails);
    expect($emails)->toHaveCount(2)
        ->and($customer->getFrom()[0]->getAddress())->toBe('no-reply@switchandsave.test')
        ->and($customer->getFrom()[0]->getName())->toBe('Kirkgate Convenience')
        ->and($customer->getReplyTo()[0]->getAddress())->toBe('orders@example.test');

    foreach ($emails as $email) {
        expect($email->getFrom()[0]->getName())->not->toContain('Switch');
    }
});

test('a verified own server sends the customer emails; the new-order email to the shop stays on the platform', function () {
    $smtp = StoreMailFakes::smtp();
    StoreMailFakes::verifiedServer($this->f->store);

    $order = ($this->place)();
    app(ChangeOnlineOrderStatus::class)->handle($this->f->company, $order->id, OnlineOrderStatus::Picking);
    app(ChangeOnlineOrderStatus::class)->handle($this->f->company, $order->id, OnlineOrderStatus::Ready);

    $own = ($this->emails)($smtp->transport->messages());
    expect($own)->toHaveCount(2)->and($own->map(fn (Email $e) => $e->getSubject())->all())->toBe(["We've got your order W-0001", 'Your order W-0001 is ready to collect']);

    foreach ($own as $email) {
        expect($email->getFrom()[0]->getAddress())->toBe('orders@kirkgate.co.uk')->and($email->getFrom()[0]->getName())->toBe('Kirkgate Butchers')
            ->and($email->getReplyTo()[0]->getAddress())->toBe('help@kirkgate.co.uk')->and($email->getTo()[0]->getAddress())->toBe('asha@example.test');
    }

    $platform = ($this->emails)(($this->platform)());
    expect($platform)->toHaveCount(1)->and($platform->first()->getSubject())->toBe('New online order W-0001')
        ->and($platform->first()->getFrom()[0]->getAddress())->toBe('no-reply@switchandsave.test')
        ->and(EmailLog::query()->where('template', 'online-order-received')->sole()->status)->toBe(EmailStatus::Sent);
});

test('an own server that has not passed a test email, or is switched off, is not used', function (array $overrides) {
    $smtp = StoreMailFakes::smtp();
    StoreMailFakes::verifiedServer($this->f->store, $overrides);

    ($this->place)();

    expect($smtp->made)->toBe(0)->and(customerEmail(($this->emails)(($this->platform)())))->not->toBeNull()
        ->and(customerEmail(($this->emails)(($this->platform)()))->getFrom()[0]->getName())->toBe('Kirkgate Butchers');
})->with([
    'not tested' => [['smtp_verified_at' => null]],
    'switched off' => [['smtp_enabled' => false]],
    'no password' => [['smtp_password' => null]],
]);

test('when the own server fails the customer still gets the email through the platform and the shop sees why', function () {
    StoreMailFakes::smtp(StoreMailFakes::authFailure());
    StoreMailFakes::verifiedServer($this->f->store);
    Log::spy();

    ($this->place)();

    $customer = customerEmail(($this->emails)(($this->platform)()));
    expect($customer)->not->toBeNull()
        ->and($customer->getFrom()[0]->getAddress())->toBe('no-reply@switchandsave.test')
        ->and($customer->getFrom()[0]->getName())->toBe('Kirkgate Butchers')
        ->and($customer->getReplyTo()[0]->getAddress())->toBe('help@kirkgate.co.uk')
        ->and($customer->getFrom())->toHaveCount(1);

    $settings = OnlineStoreMailSettings::withoutCompanyScope()->sole();
    expect($settings->last_error)->toBe('Your email server did not accept the username or password. Check them, or create an app password if your provider asks for one.')
        ->and($settings->last_error_at)->not->toBeNull()
        ->and(EmailLog::query()->where('template', 'online-order-received')->sole())->status->toBe(EmailStatus::Sent);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'own email server failed')
        && $context['company_id'] === $this->f->company->id && ! str_contains(json_encode($context), 's3cret'))->once();
});

test('a host that now resolves to a private address is never connected to: the platform sends instead', function () {
    StoreMailFakes::verifiedServer($this->f->store, ['smtp_host' => 'smtp.kirkgate.co.uk']);
    SmtpHostGuard::resolveUsing(fn () => ['10.0.0.5']);

    ($this->place)();

    expect(customerEmail(($this->emails)(($this->platform)())))->not->toBeNull()
        ->and(OnlineStoreMailSettings::withoutCompanyScope()->sole()->last_error)->toContain('private or internal network');
});

test('one business\'s own server is never used for another business\'s customers', function () {
    $smtp = StoreMailFakes::smtp();
    StoreMailFakes::verifiedServer($this->f->store);
    $other = OnlineStoreFixtures::make('Other Co', 'other-co');
    C::member($other->company, CompanyRole::Owner);

    $other->order([['productId' => $other->product('Tea'), 'quantity' => '1']], '2026-11-02T10:00:00Z');

    expect($smtp->made)->toBe(0);
    $customer = customerEmail(($this->emails)(($this->platform)()));
    expect($customer->getFrom()[0]->getName())->toBe('Other Co')->and($customer->getFrom()[0]->getAddress())->toBe('no-reply@switchandsave.test');
});

test('customer emails carry the shop\'s logo, colour, name, address and phone, with only a small Switch & Save line', function () {
    $this->f->leeds->forceFill(['address' => '12 Kirkgate', 'town' => 'Leeds', 'postcode' => 'LS1 6BY', 'phone' => '0113 496 0000'])->save();
    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['logo_path' => "online-store/{$this->f->company->id}/logo/abc.png", 'brand_colour' => '#8F1D1D']);
    $order = ($this->place)();
    $mail = null;
    app(OnlineOrderMessages::class)->forOrder($this->f->company->id, $order->id, function (OnlineOrder $o, OnlineStore $s, $company) use (&$mail) {
        $mail = new OnlineOrderReceivedMail(app(OnlineOrderMessages::class)->mailData($o, $s, $company, 'orders@example.test', forCustomer: true));
    });

    $html = (string) $mail->render();
    $text = $mail->renderText();
    expect($html)->toMatch('#<img src="https?://[^"]+/storage/online-store/'.$this->f->company->id.'/logo/abc\.png"#')
        ->toContain('background-color: #8F1D1D')->toContain('color: #FFFFFF')
        ->toContain('12 Kirkgate, Leeds, LS1 6BY')->toContain('0113 496 0000')->toContain('Kirkgate Convenience')
        ->toContain('Beans')->toContain('View your order')->toContain("/store/o/{$order->id}")
        ->toContain('Online store by Switch &amp; Save')->not->toContain('Sent by Switch')->not->toContain('switch-save-logo')
        ->not->toContain('<title>Switch')
        ->and(substr_count($html, 'Switch &amp; Save'))->toBe(1)
        ->and($text)->toContain('Kirkgate Convenience')->toContain('12 Kirkgate, Leeds, LS1 6BY')->toContain('W-0001')->toContain('Beans')
        ->toContain('Online store by Switch & Save');
});

test('a light brand colour gets dark text on it and a darker link colour that reads on white', function () {
    $brand = StoreMailBrand::make('Lemon Shop', '#FFE66D');

    expect($brand->onColour)->toBe('#111827')
        ->and(StoreMailBrand::contrast($brand->linkColour, '#FFFFFF'))->toBeGreaterThanOrEqual(4.5)
        ->and(StoreMailBrand::contrast($brand->tint, '#1F2937'))->toBeGreaterThan(12)
        ->and(StoreMailBrand::make('Forest', '#0B4A34')->onColour)->toBe('#FFFFFF')
        ->and(StoreMailBrand::make('Bad', 'red')->colour)->toBe('#0B4A34');
});

test('a sender name in the platform\'s name is never used, even if stored', function () {
    StoreMailFakes::verifiedServer($this->f->store, ['sender_name' => 'Switch & Save', 'smtp_verified_at' => null]);

    ($this->place)();

    expect(customerEmail(($this->emails)(($this->platform)()))->getFrom()[0]->getName())->toBe('Kirkgate Convenience');
});

test('the staff email keeps the Switch & Save look; samples without a store still render', function () {
    ($this->place)();
    $shop = ($this->emails)(($this->platform)())->first(fn (Email $e) => $e->getSubject() === 'New online order W-0001');

    expect($shop->getHtmlBody())->toContain('switch-save-logo')
        ->and((string) OnlineOrderReceivedMail::sample()->render())->toContain('Kirkgate Convenience')->toContain('Online store by Switch &amp; Save')
        ->and(Branch::withoutCompanyScope()->count())->toBeGreaterThan(0);
});
