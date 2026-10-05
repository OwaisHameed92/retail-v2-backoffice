<?php

use App\Domain\Customers\Actions\SaveCustomer;
use App\Domain\Customers\Actions\SendCustomerStatement;
use App\Domain\Customers\Queries\CustomerLedger;
use App\Domain\Customers\Queries\CustomerStatement;
use App\Domain\Customers\Support\MarketingConsent;
use App\Domain\Mail\Mailables\CustomerStatementMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Actions\RecomputeCustomerBalances;
use App\Domain\TillData\Enums\ConsentChannel;
use App\Domain\TillData\Models\Customer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Customers\CustomerFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;

uses(TenancyTestHelpers::class);

/** Module 4.4: customers on the tenant portal: list, details, ledger balance and points, statements, consent. */
beforeEach(function () {
    $this->travelTo('2026-10-24 12:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->leeds = $this->sync->leeds->id;
    $this->bradford = $this->sync->bradford->id;
    $this->aisha = app(SaveCustomer::class)->handle($this->company, null, [
        'name' => 'Aisha Rahman', 'card_no' => 'lc000123', 'email' => 'Aisha@Example.co.uk', 'phone' => '07700 900123', 'credit_limit' => '50',
    ]);
    $this->manager = $this->memberOf($this->company, CompanyRole::Manager);
    $this->ledger = fn (string $branch, string $type, string $amount, int $points, string $at, bool $deleted = false) => F::ledger($this->company->id, $branch, $this->aisha->id, $type, $amount, $points, $at, $deleted);
    $this->inCompany = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
});

test('SaveCustomer creates and edits only portal-owned details, bumps the row version and audits', function () {
    expect($this->aisha->card_no)->toBe('LC000123')->and($this->aisha->email)->toBe('aisha@example.co.uk')
        ->and($this->aisha->balance)->toBe('0.00')->and($this->aisha->points)->toBe(0)->and($this->aisha->row_version)->toBe(1)
        ->and($this->aisha->credit_limit)->toBe('50.00')->and(strlen($this->aisha->id))->toBe(26);

    $saved = app(SaveCustomer::class)->handle($this->company, $this->aisha->id, ['name' => 'Aisha Khan', 'balance' => '500.00', 'points' => 9000]);
    expect($saved->name)->toBe('Aisha Khan')->and($saved->balance)->toBe('0.00')->and($saved->points)->toBe(0)->and($saved->row_version)->toBe(2);

    app(SaveCustomer::class)->handle($this->company, $this->aisha->id, ['name' => 'Aisha Khan']);
    expect($saved->fresh()->row_version)->toBe(2)
        ->and(AuditLog::query()->where('action', 'like', 'customer.%')->pluck('action')->all())->toBe(['customer.created', 'customer.updated']);

    expect(fn () => app(SaveCustomer::class)->handle($this->company, null, ['name' => 'Sam', 'card_no' => 'LC000123']))->toThrow(ValidationException::class);
});

test('balance and points are the ledger sum across shops, ignoring deleted rows and the till cache', function () {
    ($this->ledger)($this->leeds, 'charge', '8.40', 0, '2026-10-01 09:00:00');
    ($this->ledger)($this->bradford, 'payment', '-5.00', 0, '2026-10-02 09:00:00');
    ($this->ledger)($this->leeds, 'pointsEarn', '0', 240, '2026-10-03 09:00:00');
    ($this->ledger)($this->bradford, 'pointsBurn', '0', -40, '2026-10-04 09:00:00');
    ($this->ledger)($this->leeds, 'charge', '100.00', 0, '2026-10-05 09:00:00', deleted: true);
    DB::table('customers')->where('id', $this->aisha->id)->update(['balance' => '77.77', 'points' => 1]);

    ($this->inCompany)(fn () => expect(CustomerLedger::totals($this->aisha->id))->toBe(['balance' => '3.40', 'points' => 200]));

    $this->actingAs($this->manager)->get("/app/customers/{$this->aisha->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/customers/show')
        ->where('account.balance', '3.40')->where('account.points', 200)->where('account.available', '46.60')->where('account.overLimit', false)
        ->has('account.byShop', 2)->where('ledger.meta.total', 4)
        ->where('ledger.data.0.type', 'pointsBurn')->where('ledger.data.0.balanceAfter', '3.40')->where('ledger.data.0.pointsAfter', 200)
        ->where('ledger.data.3.type', 'charge')->where('ledger.data.3.balanceAfter', '8.40')->where('ledger.data.3.shop', 'Leeds Kirkgate'));

    expect(app(RecomputeCustomerBalances::class)->handle($this->company->id))->toBe(1)
        ->and($this->aisha->fresh()->balance)->toBe('3.40');
});

test('the list searches by name, phone, email and card and filters by balance, points, consent and shop', function () {
    $sam = app(SaveCustomer::class)->handle($this->company, null, ['name' => 'Sam Patel', 'phone' => '0113 496 0000', 'email' => 'sam@example.com']);
    F::ledger($this->company->id, $this->bradford, $sam->id, 'pointsEarn', '0', 50, '2026-10-01 10:00:00');
    ($this->ledger)($this->leeds, 'charge', '12.00', 0, '2026-10-01 09:00:00');
    app(RecomputeCustomerBalances::class)->handle($this->company->id);
    F::consent($this->company->id, $this->leeds, $this->aisha->id, 'email', true, '2026-09-01 10:00:00');
    F::consent($this->company->id, $this->leeds, $sam->id, 'email', true, '2026-09-01 10:00:00');
    F::consent($this->company->id, $this->bradford, $sam->id, 'email', false, '2026-10-01 10:00:00');

    $names = fn (string $query) => collect($this->actingAs($this->manager)->get('/app/customers'.$query)->assertOk()
        ->viewData('page')['props']['customers']['data'])->pluck('name')->all();

    expect($names(''))->toBe(['Aisha Rahman', 'Sam Patel'])
        ->and($names('?search=patel'))->toBe(['Sam Patel'])
        ->and($names('?search=lc000123'))->toBe(['Aisha Rahman'])
        ->and($names('?search=01134960000'))->toBe(['Sam Patel'])
        ->and($names('?search=aisha@'))->toBe(['Aisha Rahman'])
        ->and($names('?balance=owes'))->toBe(['Aisha Rahman'])
        ->and($names('?balance=credit'))->toBe([])
        ->and($names('?points=has'))->toBe(['Sam Patel'])
        ->and($names('?consent=email'))->toBe(['Aisha Rahman'])
        ->and($names('?consent=sms'))->toBe([])
        ->and($names("?shop={$this->bradford}"))->toBe(['Sam Patel'])
        ->and($names('?sort=balance&direction=desc'))->toBe(['Aisha Rahman', 'Sam Patel']);

    $this->actingAs($this->manager)->get('/app/customers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('counts', ['all' => 2, 'owing' => 1, 'owed' => '12.00', 'creditHeld' => '0.00', 'points' => 50, 'emailConsent' => 1])->where('canEdit', true));
});

test('marketing consent is the latest answer per channel, with its history; never asked means no consent', function () {
    F::consent($this->company->id, $this->leeds, $this->aisha->id, 'email', true, '2026-09-01 10:00:00', source: 'form');
    F::consent($this->company->id, $this->bradford, $this->aisha->id, 'sms', true, '2026-09-02 10:00:00', '2026-10-02 10:00:00');

    ($this->inCompany)(function () {
        $current = MarketingConsent::current($this->aisha->id);
        expect($current['email']['state'])->toBe('given')->and($current['email']['source'])->toBe('Paper form')
            ->and($current['sms']['state'])->toBe('withdrawn')->and($current['sms']['at'])->toBe('2026-10-02T10:00:00Z')
            ->and($current['post']['state'])->toBe('none')
            ->and(MarketingConsent::allows($this->aisha->id, ConsentChannel::Email))->toBeTrue()
            ->and(MarketingConsent::allows($this->aisha->id, ConsentChannel::Sms))->toBeFalse()
            ->and(MarketingConsent::allows($this->aisha->id, ConsentChannel::WhatsApp))->toBeFalse()
            ->and(array_column(MarketingConsent::history($this->aisha->id, []), 'event'))->toBe(['withdrawn', 'given', 'given']);
    });
});

test('a statement has the opening balance, the rows of the range with running figures, totals and the closing balance', function () {
    ($this->ledger)($this->leeds, 'opening', '20.00', 100, '2026-09-15 09:00:00');
    ($this->ledger)($this->leeds, 'charge', '8.40', 0, '2026-10-01 08:30:00');      // 09:30 London: in October
    ($this->ledger)($this->bradford, 'payment', '-25.00', 0, '2026-10-10 12:00:00');
    ($this->ledger)($this->leeds, 'pointsEarn', '0', 30, '2026-11-01 00:30:00');   // 1 Nov London (GMT): next month
    ($this->ledger)($this->leeds, 'charge', '5.00', 0, '2026-09-30 23:30:00');     // 1 Oct 00:30 London: in October

    $s = ($this->inCompany)(fn () => CustomerStatement::for($this->company, $this->aisha->fresh(), '2026-10-01', '2026-10-31'));

    expect($s['opening'])->toBe(['balance' => '20.00', 'points' => 100])
        ->and(array_column($s['rows'], 'balanceAfter'))->toBe(['25.00', '33.40', '8.40'])
        ->and($s['totals'])->toBe(['charges' => '13.40', 'credits' => '25.00', 'pointsEarned' => 0, 'pointsUsed' => 0])
        ->and($s['closing'])->toBe(['balance' => '8.40', 'points' => 100])
        ->and($s['period'])->toBe('1 Oct – 31 Oct 2026');

    $this->actingAs($this->manager)->get("/app/customers/{$this->aisha->id}/statement?from=2026-10-01&to=2026-10-31")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('app/customers/statement')->where('statement.closing.balance', '8.40')->where('canEmail', true));
    $pdf = $this->actingAs($this->manager)->get("/app/customers/{$this->aisha->id}/statement/pdf?from=2026-10-01&to=2026-10-31")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF')
        ->and($pdf->headers->get('Content-Disposition'))->toContain('statement-lc000123-2026-10-01-to-2026-10-31.pdf');

    $this->actingAs($this->manager)->get("/app/customers/{$this->aisha->id}/statement?from=2026-10-31&to=2026-10-01")->assertSessionHasErrors('to');
    $this->actingAs($this->manager)->get("/app/customers/{$this->aisha->id}/statement?from=2020-01-01&to=2026-10-01")->assertSessionHasErrors('to');
});

test('statements are emailed to the customer\'s own address with the PDF; never without an email', function () {
    Mail::fake();
    ($this->ledger)($this->leeds, 'charge', '8.40', 0, '2026-10-01 09:00:00');

    $this->actingAs($this->manager)->post("/app/customers/{$this->aisha->id}/statement/email?from=2026-10-01&to=2026-10-24")
        ->assertRedirect()->assertSessionHas('success');
    Mail::assertQueued(CustomerStatementMail::class, function (CustomerStatementMail $mail) {
        $attachments = $mail->attachments();

        return $mail->hasTo('aisha@example.co.uk') && $mail->data->closingBalance === '8.40' && count($attachments) === 1
            && $mail->data->companyId === $this->company->id;
    });
    expect(AuditLog::query()->where('action', 'customer.statement_sent')->count())->toBe(1);

    $sam = app(SaveCustomer::class)->handle($this->company, null, ['name' => 'Sam Patel']);
    expect(fn () => app(SendCustomerStatement::class)->handle($this->company, $sam->id, '2026-10-01', '2026-10-24'))->toThrow(ValidationException::class);
    Mail::assertQueuedCount(1);
});

test('managers add and edit customers through the screens', function () {
    $this->actingAs($this->manager)->get('/app/customers/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('app/customers/create'));
    $this->actingAs($this->manager)->post('/app/customers', ['name' => 'Sam Patel', 'card_no' => 'LC9', 'credit_limit' => '25', 'is_active' => true])->assertRedirect();
    $sam = Customer::withoutCompanyScope()->where('name', 'Sam Patel')->sole();
    expect([$sam->card_no, $sam->credit_limit, $sam->company_id])->toBe(['LC9', '25.00', $this->company->id]);

    $this->actingAs($this->manager)->post('/app/customers', ['name' => '', 'email' => 'nope'])->assertSessionHasErrors(['name', 'email']);
    $this->actingAs($this->manager)->put("/app/customers/{$sam->id}", ['name' => 'Sam Patel', 'card_no' => 'LC000123'])->assertSessionHasErrors('card_no');
    $this->actingAs($this->manager)->put("/app/customers/{$sam->id}", ['name' => 'Samir Patel', 'tier' => 'Gold', 'is_active' => false])->assertSessionHas('success');
    expect($sam->fresh()->name)->toBe('Samir Patel')->and($sam->fresh()->tier)->toBe('Gold')->and($sam->fresh()->is_active)->toBeFalse();
});

test('guests are sent to login; accountants get 403; staff and one-shop managers may only look', function (?CompanyRole $role, bool $oneShop) {
    $reads = ['/app/customers', "/app/customers/{$this->aisha->id}", "/app/customers/{$this->aisha->id}/statement", "/app/customers/{$this->aisha->id}/statement/pdf"];
    $writes = [['get', '/app/customers/create', []], ['post', '/app/customers', ['name' => 'X']], ['put', "/app/customers/{$this->aisha->id}", ['name' => 'X']]];

    if ($role === null) {
        foreach ($reads as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        foreach ($writes as [$method, $url, $data]) {
            $this->{$method}($url, $data)->assertRedirect('/login');
        }
        $this->post("/app/customers/{$this->aisha->id}/statement/email")->assertRedirect('/login');

        return;
    }

    $user = $this->memberOf($this->company, $role);
    if ($oneShop) {
        $this->company->users()->updateExistingPivot($user->id, ['branch_id' => $this->leeds]);
    }
    $mayRead = $role !== CompanyRole::Accountant;

    foreach ($reads as $url) {
        $mayRead ? $this->actingAs($user)->get($url)->assertOk() : $this->actingAs($user)->get($url)->assertForbidden();
    }
    foreach ($writes as [$method, $url, $data]) {
        $this->actingAs($user)->{$method}($url, $data)->assertForbidden();
    }
    if ($role !== CompanyRole::Manager) {
        $this->actingAs($user)->post("/app/customers/{$this->aisha->id}/statement/email")->assertForbidden();
    }
    if ($mayRead) {
        $this->actingAs($user)->get("/app/customers/{$this->aisha->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('canEdit', false));
    }

    expect(Customer::withoutCompanyScope()->count())->toBe(1)->and($this->aisha->fresh()->name)->toBe('Aisha Rahman');
})->with([[null, false], [CompanyRole::Accountant, false], [CompanyRole::Staff, false], [CompanyRole::Manager, true]]);

test('tenant isolation: another business cannot see or change these customers, ledger or consent', function () {
    ($this->ledger)($this->leeds, 'charge', '8.40', 0, '2026-10-01 09:00:00');
    $other = Company::factory()->create();
    $otherShop = Branch::factory()->forCompany($other)->create(['code' => 'OTH']);
    $stranger = $this->memberOf($other, CompanyRole::Owner);
    $theirs = app(SaveCustomer::class)->handle($other, null, ['name' => 'Their Customer']);
    F::ledger($other->id, $otherShop->id, $theirs->id, 'charge', '99.00', 0, '2026-10-01 09:00:00');
    F::ledger($other->id, $otherShop->id, $this->aisha->id, 'charge', '50.00', 0, '2026-10-01 09:00:00'); // same customer id, other business

    foreach (["/app/customers/{$this->aisha->id}", "/app/customers/{$this->aisha->id}/statement", "/app/customers/{$this->aisha->id}/statement/pdf"] as $url) {
        $this->actingAs($stranger)->get($url)->assertNotFound();
    }
    $this->actingAs($stranger)->put("/app/customers/{$this->aisha->id}", ['name' => 'Hacked'])->assertNotFound();
    $this->actingAs($stranger)->post("/app/customers/{$this->aisha->id}/statement/email")->assertNotFound();
    $this->actingAs($stranger)->get('/app/customers')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('customers.data', 1)->where('customers.data.0.name', 'Their Customer')->where('counts.all', 1));

    $this->actingAs($this->manager)->get("/app/customers/{$this->aisha->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('account.balance', '8.40')->where('ledger.meta.total', 1));
    expect($this->aisha->fresh()->name)->toBe('Aisha Rahman');
});
