<?php

use App\Domain\Ai\Models\AiUsage;
use App\Domain\Ai\MorningSummary\Models\MorningSummary;
use App\Domain\Ai\MorningSummary\Support\NarrativeCheck;
use App\Domain\Ai\Testing\FakeAiClient;
use App\Domain\Mail\Mailables\OwnerDigestMail;
use App\Domain\Mail\Support\EmailTemplates;
use App\Domain\Notifications\Actions\SendDigests;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Models\AlertNotification;
use App\Domain\Notifications\Models\AlertPreference;
use App\Domain\Notifications\Support\AlertLinks;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Ai\MorningSummaryFixtures as M;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Stock\StockFixtures as S;
use Tests\Feature\TillData\TillFixtures as T;

/* Module 6.3: the morning summary in the 07:00 email and on the dashboard. Figures from data; the model only writes. */

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    config(['ai.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse(M::NOW, 'Europe/London'));
    [$this->company] = T::tenant();
    M::plan($this->company);
    M::kirkgate($this->company);
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $this->fake = FakeAiClient::install();
    $this->summaryOf = fn (string $email): ?array => M::mailTo($email)->data->summary;
});

test('the figures are worked out from the data: shops, last week, last year, movers and what to watch', function () {
    $this->fake->replyWith(M::goodNarrative());

    app(SendDigests::class)->handle();

    $s = ($this->summaryOf)($this->owner->email);
    expect($s['dayLabel'])->toBe('Wednesday 7 October 2026')
        ->and($s['scope'])->toBe('All 2 shops')
        ->and($s['total'])->toMatchArray(['sales' => '1200.00', 'transactions' => 100, 'lastWeek' => '1500.00', 'lastWeekChange' => '-20.0',
            'lastYear' => '960.00', 'lastYearChange' => '25.0', 'average' => '12.00'])
        ->and($s['shops'][0])->toMatchArray(['name' => 'Leeds', 'sales' => '1200.00', 'lastWeekChange' => '20.0', 'lastYearChange' => '25.0'])
        ->and($s['shops'][1])->toMatchArray(['name' => 'Bradford', 'sales' => '0.00', 'lastWeek' => '500.00', 'lastWeekChange' => '-100.0', 'lastYear' => null])
        ->and($s['moversUp'])->toBe([['name' => 'Cola 500ml', 'sales' => '84.00', 'before' => '60.00', 'difference' => '24.00']])
        ->and($s['moversDown'])->toBe([['name' => 'Bread', 'sales' => '12.50', 'before' => '30.00', 'difference' => '-17.50']])
        ->and(array_column($s['watch'], 'text'))->toBe([
            'Bradford: no sales reached the portal for yesterday; the same day last week took £500.00',
            'Leeds: £60.00 refunded (3 refunds) yesterday, against a usual £10.00 a day',
            'Leeds: Cola 500ml is low, 2 on hand with 30 sold in the last 7 days',
        ])
        ->and($s['narrative'])->toBe(M::goodNarrative());

    // The model got the computed facts only, on the fast tier, as a metered call for this business.
    $request = $this->fake->lastRequest();
    expect($request->model)->toBe(config('ai.models.fast'))
        ->and($request->lastUserText())->toContain('<facts>', '£1,200.00', '-20.0%', 'Cola 500ml')
        ->and($request->tools)->toBe([]);
    $usage = AiUsage::query()->sole();
    expect($usage->company_id)->toBe($this->company->id)->and($usage->feature->value)->toBe('morningSummary');
});

test('a narrative with a number that is not in the facts is dropped; the summary still goes', function () {
    $this->fake->replyWith('Sales were £1,250.00 yesterday, up on last week.');

    app(SendDigests::class)->handle();

    $mail = M::mailTo($this->owner->email);
    expect($mail->data->summary['narrative'])->toBeNull()
        ->and($mail->data->summary['total']['sales'])->toBe('1200.00')
        ->and(MorningSummary::withoutCompanyScope()->sole()->narrative_status)->toBe('rejected');
    $mail->assertSeeInHtml('Your morning summary')->assertDontSeeInHtml('1,250');
});

test('the number check: separators, pounds, percentages and trailing zeros match; anything else fails', function () {
    $facts = ['sales' => '£1,234.50', 'change' => '+12.0%', 'shop' => 'Till 7 at Leeds', 'items' => [['qty' => 30]]];

    expect(NarrativeCheck::numbers('£1,234.50, 12% and 07 then 0.50'))->toBe(['1234.5', '12', '7', '0.5'])
        ->and(NarrativeCheck::clean('You took £1,234.50, up 12%. Till 7 sold 30.', $facts))->toBe('You took £1,234.50, up 12%. Till 7 sold 30.')
        ->and(NarrativeCheck::clean('You took £1,234.51.', $facts))->toBeNull()
        ->and(NarrativeCheck::clean('You took 1234.5 pounds, 3 more than usual.', $facts))->toBeNull()
        ->and(NarrativeCheck::clean('**You took £1,234.50.**', $facts))->toBeNull()
        ->and(NarrativeCheck::clean('See https://example.com for £1,234.50.', $facts))->toBeNull()
        ->and(NarrativeCheck::clean(str_repeat('Sales were fine. ', 7), $facts))->toBeNull()
        ->and(NarrativeCheck::clean('  ', $facts))->toBeNull();
});

test('without an API key (or without the plan feature) the summary goes without the paragraph and nothing is metered', function () {
    $this->fake->notConfigured();

    app(SendDigests::class)->handle();

    expect(($this->summaryOf)($this->owner->email)['narrative'])->toBeNull()
        ->and(MorningSummary::withoutCompanyScope()->sole()->narrative_status)->toBe('notConfigured')
        ->and($this->fake->requests)->toBe([])
        ->and(AiUsage::query()->count())->toBe(0);
    M::mailTo($this->owner->email)->assertSeeInHtml('£1,200.00')->assertDontSeeInHtml('written by AI');

    // Not in the plan: same fallback.
    Mail::fake();
    MorningSummary::withoutCompanyScope()->delete();
    $this->travelTo(now()->addDay());
    M::plan($this->company, withAi: false);
    $fake = FakeAiClient::install();
    app(SendDigests::class)->handle();
    expect(MorningSummary::withoutCompanyScope()->sole()->narrative_status)->toBe('notInPlan')->and($fake->requests)->toBe([]);
});

test('one email per user each morning: digest sections and the summary together, the paragraph written once for the same shops', function () {
    $second = C::member($this->company, CompanyRole::Owner);
    S::line($this->company->id, T::BRADFORD, S::id('MS', 2), '-1');
    $this->fake->replyWith(M::goodNarrative());

    $this->artisan('alerts:digest')->assertSuccessful();
    $this->artisan('alerts:digest')->assertSuccessful();

    Mail::assertQueued(OwnerDigestMail::class, 2);
    $mail = M::mailTo($this->owner->email);
    expect(array_column($mail->data->sections, 'type'))->toBe(['lowStock'])
        ->and($mail->data->summary['narrative'])->toBe(M::goodNarrative())
        ->and(M::mailTo($second->email)->data->summary['narrative'])->toBe(M::goodNarrative())
        ->and($this->fake->requests)->toHaveCount(1)
        ->and($mail->subjectLine())->toBe('Your morning summary for Kirkgate Convenience: £1,200.00 sales yesterday, 1 thing to check')
        ->and(AlertNotification::withoutCompanyScope()->where('user_id', $this->owner->id)->sole()->title)
        ->toBe('Morning summary: £1,200.00 sales yesterday, 1 thing to check');
    $mail->assertSeeInHtml('Top movers against last week')->assertSeeInHtml('Low and negative stock')->assertDontSeeInHtml('Your daily summary');
});

test('preferences: off means the digest alone; accountants are off by default; a one-shop manager gets only their shop', function () {
    $accountant = C::member($this->company, CompanyRole::Accountant);
    $manager = C::member($this->company, CompanyRole::Manager, T::BRADFORD);
    AlertPreference::withoutCompanyScope()->create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'deliveries' => ['morningSummary' => 'off']]);
    $this->fake->replyWith('Bradford had no sales reach the portal yesterday, against £500.00 on the same day last week.');

    app(SendDigests::class)->handle();

    $ownerMail = M::mailTo($this->owner->email);
    expect($ownerMail->data->summary)->toBeNull()->and(array_column($ownerMail->data->sections, 'type'))->toBe(['lowStock'])
        ->and($ownerMail->subjectLine())->toBe('Your daily summary for Kirkgate Convenience: 1 thing to check');
    Mail::assertNotQueued(OwnerDigestMail::class, fn (OwnerDigestMail $m) => $m->hasTo($accountant->email));
    $s = ($this->summaryOf)($manager->email);
    expect($s['scope'])->toBe('Bradford')
        ->and($s['total'])->toMatchArray(['sales' => '0.00', 'lastWeek' => '500.00'])
        ->and(array_column($s['shops'], 'name'))->toBe(['Bradford'])
        ->and($s['moversUp'])->toBe([])
        ->and(json_encode($s))->not->toContain('Leeds')
        ->and($this->fake->lastRequest()->lastUserText())->not->toContain('Leeds', '1,200')
        ->and($s['unsubscribeUrl'])->toContain('/morningSummary?signature=');

    // The signed link turns just the summary off.
    $this->post($s['unsubscribeUrl'])->assertOk();
    expect(AlertPreference::withoutCompanyScope()->where('user_id', $manager->id)->sole()->deliveries['morningSummary'])->toBe('off');
});

test('a business with no sales and nothing to report gets no summary and no model call', function () {
    $quiet = Company::factory()->create(['name' => 'Quiet Shop']);
    Branch::factory()->forCompany($quiet)->create(['code' => 'Q1', 'name' => 'Quiet']);
    M::plan($quiet);
    $quietOwner = C::member($quiet, CompanyRole::Owner);

    $totals = app(SendDigests::class)->handle(companyIds: [$quiet->id]);

    expect($totals['empty'])->toBe(1)->and($this->fake->requests)->toBe([]);
    Mail::assertNotQueued(OwnerDigestMail::class, fn (OwnerDigestMail $m) => $m->hasTo($quietOwner->email));
});

test('one business\'s figures never reach another business\'s summary, model call or dashboard', function () {
    $other = Company::factory()->create(['name' => 'Patel News']);
    $shop = Branch::factory()->forCompany($other)->create(['code' => 'P1', 'name' => 'Patel High St']);
    M::plan($other);
    $otherOwner = C::member($other, CompanyRole::Owner);
    M::sales($other->id, $shop->id, '2026-10-07', '333.30', 30);
    $this->fake->replyWith(M::goodNarrative())->replyWith('Patel High St took £333.30 yesterday.');

    app(SendDigests::class)->handle();

    $mine = ($this->summaryOf)($this->owner->email);
    $theirs = ($this->summaryOf)($otherOwner->email);
    expect(json_encode($mine))->not->toContain('Patel', '333.30')
        ->and($theirs['total']['sales'])->toBe('333.30')
        ->and(json_encode($theirs))->not->toContain('Leeds', 'Cola')
        ->and(AiUsage::query()->where('company_id', $other->id)->count())->toBe(1)
        ->and(AiUsage::query()->where('company_id', $this->company->id)->count())->toBe(1);

    $this->withoutVite()->actingAs($otherOwner)->get('/app')->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('yesterday', fn (Assert $reload) => $reload
            ->where('yesterday.total.sales', '333.30')->where('yesterday.scope', 'Patel High St')
            ->where('yesterday.narrative', 'Patel High St took £333.30 yesterday.')));
});

test('the dashboard card shows yesterday for the chosen shop, with the email paragraph when it covered the same shops', function () {
    $this->fake->replyWith(M::goodNarrative());
    app(SendDigests::class)->handle();

    $this->withoutVite()->actingAs($this->owner)->get('/app')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->missing('yesterday')
        ->loadDeferredProps('yesterday', fn (Assert $reload) => $reload
            ->where('yesterday.total.sales', '1200.00')
            ->where('yesterday.narrative', M::goodNarrative())
            ->has('yesterday.shops', 2)));

    // A one-shop manager: their shop only, and no paragraph (no email went for that scope).
    $manager = C::member($this->company, CompanyRole::Manager, T::LEEDS);
    $this->actingAs($manager)->get('/app')->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('yesterday', fn (Assert $reload) => $reload
            ->where('yesterday.scope', 'Leeds')->has('yesterday.shops', 1)->where('yesterday.narrative', null)));

    // Staff do not see sales, so no card.
    $staff = C::member($this->company, CompanyRole::Staff);
    $this->actingAs($staff)->get('/app')->assertInertia(fn (Assert $page) => $page->where('yesterday', null));
});

test('the admin email preview shows the morning summary', function () {
    $mail = OwnerDigestMail::sample();

    expect(EmailTemplates::find('owner-digest'))->toBe(OwnerDigestMail::class)
        ->and($mail->data->summary)->not->toBeNull()
        ->and($mail->subjectLine())->toContain('Your morning summary for Khan Mini Mart');
    $mail->assertSeeInHtml('Your morning summary')->assertSeeInHtml('Top movers against last week')->assertSeeInHtml('Worth a look');
    expect(AlertLinks::forType(AlertType::MorningSummary))->toEndWith('/app');
});

test('stored summaries are pruned after the AI retention period', function () {
    $this->fake->replyWith(M::goodNarrative());
    app(SendDigests::class)->handle();
    expect(MorningSummary::withoutCompanyScope()->count())->toBe(1);

    $this->travelTo(now()->addDays((int) config('ai.retention_days') + 1));
    $this->artisan('model:prune', ['--model' => [MorningSummary::class]])->assertSuccessful();

    expect(MorningSummary::withoutCompanyScope()->count())->toBe(0);
});
