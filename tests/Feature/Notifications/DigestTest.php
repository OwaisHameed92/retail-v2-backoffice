<?php

use App\Domain\Mail\Mailables\OwnerDigestMail;
use App\Domain\Notifications\Actions\SendDigests;
use App\Domain\Notifications\Models\AlertDispatch;
use App\Domain\Notifications\Models\AlertNotification;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Compliance\ComplianceFixtures as F;
use Tests\Feature\Stock\StockFixtures as S;
use Tests\Feature\TillData\TillFixtures as T;

/* Module 7.8: the 07:00 daily digest: low stock, cash variances, compliance, cut per user, once a day. */

beforeEach(function () {
    Mail::fake();
    // Thursday 8 Oct 2026, 07:00 London.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:00', 'Europe/London'));
    [$this->company] = T::tenant();
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $id = $this->company->id;

    // Stock: Leeds one below zero, one out, one low (default low-stock point 5), one fine; Bradford one low.
    foreach (['Cola 500ml', 'Crisps', 'Bread', 'Milk'] as $n => $name) {
        S::product($id, S::id('P', $n + 1), $name);
    }
    S::line($id, T::LEEDS, S::id('P', 1), '-2');
    S::line($id, T::LEEDS, S::id('P', 2), '0');
    S::line($id, T::LEEDS, S::id('P', 3), '3');
    S::line($id, T::LEEDS, S::id('P', 4), '50');
    S::line($id, T::BRADFORD, S::id('P', 3), '1');

    // Cash: yesterday a Leeds shift closed £12.40 short in cash (over the default £5 alert amount).
    C::shift($id, T::LEEDS, T::TILL_1, S::id('SH', 1), ['status' => 'closed', 'closed_at' => '2026-10-07 18:00:00', 'variance_total' => '-12.40'], ['200.00', '187.60', '-12.40']);

    // Compliance: Leeds training expiring in a week; a Bradford licence expired long ago (not repeated); an open recall.
    F::staff($id);
    F::row('training_records', ['id' => F::id('T1'), 'company_id' => $id, 'branch_id' => T::LEEDS, 'user_id' => F::ALI, 'topic' => 'Challenge 25', 'trained_on' => '2025-10-15', 'expires_on' => '2026-10-15']);
    F::row('compliance_licences', ['id' => F::id('C1'), 'company_id' => $id, 'branch_id' => T::BRADFORD, 'licence_type' => 'Premises', 'number' => 'P1', 'issued_on' => '2020-01-01', 'expires_on' => '2025-01-01']);
    F::row('product_recalls', ['id' => F::id('R1'), 'company_id' => $id, 'reference' => 'RC-1', 'product_name' => 'Pork pies', 'batch_code' => 'L22', 'status' => 'open', 'raised_at' => '2026-10-06 09:00:00', 'row_version' => 1]);

    $this->digestFor = function (string $email): OwnerDigestMail {
        $found = Mail::queued(OwnerDigestMail::class, fn (OwnerDigestMail $mail) => $mail->hasTo($email));
        expect($found)->toHaveCount(1);

        return $found->first();
    };
});

test('the owner gets one digest with low stock, yesterday\'s cash variance and compliance, worst first', function () {
    $totals = app(SendDigests::class)->handle();

    expect($totals)->toMatchArray(['sent' => 1, 'companies' => 1]);
    $mail = ($this->digestFor)($this->owner->email);
    $sections = collect($mail->data->sections)->keyBy('type');

    expect($sections->keys()->all())->toBe(['lowStock', 'cashVariance', 'compliance'])
        ->and($sections['lowStock']['summary'])->toBe('4 product lines at or below the low-stock point, 1 out of stock, 1 below zero.')
        ->and($sections['lowStock']['items'][0])->toBe('Leeds: Cola 500ml, -2 on hand')
        ->and($sections['lowStock']['items'])->toContain('Leeds: Bread, 3 on hand (low at 5)', 'Bradford: Bread, 1 on hand (low at 5)')
        ->and($sections['cashVariance']['items'])->toBe(['Leeds, Till 1: Cash £12.40 short'])
        ->and($sections['compliance']['summary'])->toBe('1 expiring within 14 days, 1 open recall.')
        ->and($sections['compliance']['items'])->toBe(['Leeds: Ali Khan: Challenge 25 expires 15 Oct 2026 (7 days)', 'Recall: Pork pies batch L22'])
        ->and($sections['lowStock']['unsubscribeUrl'])->toContain('/app/alerts/unsubscribe/'.$this->company->id.'/'.$this->owner->id.'/lowStock?signature=')
        ->and($mail->data->unsubscribeUrl)->toContain('/digest?signature=')
        ->and($mail->subjectLine())->toBe('Your daily summary for Kirkgate Convenience: 3 things to check');

    $mail->assertSeeInHtml('Cola 500ml');
    expect(AlertNotification::withoutCompanyScope()->where('user_id', $this->owner->id)->sole()->title)->toBe('Daily summary: 3 things to check');
});

test('a digest goes once per London day, however often the command runs', function () {
    $this->artisan('alerts:digest')->assertSuccessful();
    $this->artisan('alerts:digest')->assertSuccessful();

    Mail::assertQueued(OwnerDigestMail::class, 1);
    expect(AlertDispatch::withoutCompanyScope()->where('subject_key', 'digest|2026-10-08')->count())->toBe(1);

    $this->travelTo(now()->addDay());
    app(SendDigests::class)->handle();
    Mail::assertQueued(OwnerDigestMail::class, 2);
});

test('a one-shop manager gets only their shop, plus recalls that affect every shop', function () {
    $manager = C::member($this->company, CompanyRole::Manager, T::BRADFORD);

    app(SendDigests::class)->handle();

    $sections = collect(($this->digestFor)($manager->email)->data->sections)->keyBy('type');
    expect($sections->keys()->all())->toBe(['lowStock', 'compliance'])
        ->and($sections['lowStock']['items'])->toBe(['Bradford: Bread, 1 on hand (low at 5)'])
        ->and($sections['lowStock']['url'])->toBe(config('sspos.portal_url').'/app/stock?shop='.T::BRADFORD.'&status=low')
        ->and($sections['compliance']['items'])->toBe(['Recall: Pork pies batch L22']);
});

test('roles decide the defaults: staff get nothing, accountants only cash', function () {
    $staff = C::member($this->company, CompanyRole::Staff);
    $accountant = C::member($this->company, CompanyRole::Accountant);

    app(SendDigests::class)->handle();

    Mail::assertNotQueued(OwnerDigestMail::class, fn (OwnerDigestMail $mail) => $mail->hasTo($staff->email));
    expect(array_column(($this->digestFor)($accountant->email)->data->sections, 'type'))->toBe(['cashVariance']);
});

test('nothing to say means no email; suspended businesses get none', function () {
    $quiet = Company::factory()->create(['name' => 'Quiet Shop']);
    Branch::factory()->forCompany($quiet)->create(['code' => 'Q1', 'name' => 'Quiet']);
    $quietOwner = C::member($quiet, CompanyRole::Owner);
    $this->company->forceFill(['status' => CompanyStatus::Suspended])->save();

    $totals = app(SendDigests::class)->handle();

    expect($totals['sent'])->toBe(0)->and($totals['empty'])->toBe(1);
    Mail::assertNotQueued(OwnerDigestMail::class);
    expect(AlertNotification::withoutCompanyScope()->where('user_id', $quietOwner->id)->count())->toBe(0);
});

test('one business\'s stock never shows in another business\'s digest', function () {
    $other = Company::factory()->create(['name' => 'Patel News']);
    $shop = Branch::factory()->forCompany($other)->create(['code' => 'P1', 'name' => 'Patel High St']);
    $otherOwner = C::member($other, CompanyRole::Owner);
    S::product($other->id, S::id('PX', 1), 'Their beans');
    S::line($other->id, $shop->id, S::id('PX', 1), '-7');

    app(SendDigests::class)->handle();

    $mine = collect(($this->digestFor)($this->owner->email)->data->sections)->flatMap(fn (array $s) => $s['items'])->implode("\n");
    $theirs = ($this->digestFor)($otherOwner->email)->data->sections;
    expect($mine)->not->toContain('Their beans')
        ->and(array_column($theirs, 'type'))->toBe(['lowStock'])
        ->and($theirs[0]['items'])->toBe(['Patel High St: Their beans, -7 on hand']);
});

test('the digest and urgent checks are scheduled', function () {
    $events = collect(app(Schedule::class)->events());
    $digest = $events->first(fn ($event) => str_contains((string) $event->command, 'alerts:digest'));
    $check = $events->first(fn ($event) => str_contains((string) $event->command, 'alerts:check'));

    expect($digest)->not->toBeNull()
        ->and($digest->expression)->toBe('0 7 * * *')
        ->and($digest->timezone)->toBe('Europe/London')
        ->and($check)->not->toBeNull()
        ->and($check->expression)->toBe('2-59/5 * * * *');
});
