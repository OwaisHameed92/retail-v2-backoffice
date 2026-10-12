<?php

use App\Domain\Mail\Mailables\OwnerDigestMail;
use App\Domain\Notifications\Actions\SendDigests;
use App\Domain\Notifications\Models\AlertDispatch;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Shared\FailingBusiness;
use Tests\Feature\Stock\StockFixtures as S;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * Audit DATA-2: alerts:digest isolates its businesses (one business's error is reported and the others still get
 * their digest; the command exits non-zero naming it), and a failure between "sent" and the email never makes a
 * rerun send the digest twice.
 */

beforeEach(function () {
    Mail::fake();
    Exceptions::fake();
    // Thursday 8 Oct 2026, 07:00 London.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:00', 'Europe/London'));
    [$this->first] = T::tenant();
    $this->second = Company::factory()->create(['name' => 'Patel News']);
    $secondShop = Branch::factory()->forCompany($this->second)->create(['code' => 'PTL', 'name' => 'Patel']);
    $this->ownerOne = C::member($this->first, CompanyRole::Owner);
    $this->ownerTwo = C::member($this->second, CompanyRole::Owner);
    // Something to say for both: an item out of stock.
    S::product($this->first->id, S::id('PA', 1), 'Cola');
    S::line($this->first->id, T::LEEDS, S::id('PA', 1), '0');
    S::product($this->second->id, S::id('PB', 1), 'Crisps');
    S::line($this->second->id, $secondShop->id, S::id('PB', 1), '0');
    $this->ids = [$this->first->id, $this->second->id];
    $this->digests = fn (string $email) => Mail::queued(OwnerDigestMail::class, fn (OwnerDigestMail $m) => $m->hasTo($email))->count();
});

test('one business that fails does not stop the digest of the others', function () {
    $failing = FailingBusiness::on('alert_dispatches', $this->ids);

    $totals = app(SendDigests::class)->handle();
    $failing->stop();
    $owners = [$this->first->id => $this->ownerOne->email, $this->second->id => $this->ownerTwo->email];

    expect($totals['sent'])->toBe(1)
        ->and(($this->digests)($owners[$failing->other($this->ids)]))->toBe(1)
        ->and(($this->digests)($owners[$failing->failed]))->toBe(0)
        ->and(app(SendDigests::class)->failedCompanyIds())->toBe([$failing->failed]);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === "Simulated failure for business {$failing->failed}");
});

test('alerts:digest exits non-zero naming the business that failed, after the others got theirs', function () {
    FailingBusiness::on('alert_dispatches', [$this->first->id]);

    $this->artisan('alerts:digest')->expectsOutputToContain('Queued 1 digests')->expectsOutputToContain($this->first->id)->assertFailed();

    expect(($this->digests)($this->ownerTwo->email))->toBe(1)
        ->and(($this->digests)($this->ownerOne->email))->toBe(0);
});

test('a failure while marking the digest sent sends nothing, and the rerun sends it once', function () {
    // The bell entry fails after the "sent" row was written (same transaction): before the fix the email was
    // already queued and the rerun queued it again.
    $failing = FailingBusiness::on('alert_notifications', [$this->first->id]);

    app(SendDigests::class)->handle();
    $failing->stop();

    expect(($this->digests)($this->ownerOne->email))->toBe(0)
        ->and(AlertDispatch::withoutCompanyScope()->where('company_id', $this->first->id)->count())->toBe(0);

    app(SendDigests::class)->handle();
    app(SendDigests::class)->handle();

    expect(($this->digests)($this->ownerOne->email))->toBe(1)
        ->and(($this->digests)($this->ownerTwo->email))->toBe(1)
        ->and(AlertDispatch::withoutCompanyScope()->where('company_id', $this->first->id)->count())->toBe(1);
});
