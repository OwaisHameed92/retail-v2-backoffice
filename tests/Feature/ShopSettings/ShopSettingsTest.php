<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\ShopSettings\Actions\SaveShopSettings;
use App\Domain\ShopSettings\Support\SettingCatalogue;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Actions\SaveTillSetting;
use App\Domain\TillData\Enums\SettingScope;
use App\Domain\TillData\Models\TillSetting;
use App\Domain\TillData\Sync\SettingSyncPolicy;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;
use Tests\Feature\TillData\TillFixtures;

uses(TenancyTestHelpers::class);

/** Module 4.9: the Till settings page (contract §10.3, §18.4 item 7, §18.6), `settings.manage`. */
beforeEach(function () {
    $this->withoutVite();
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-10-28 10:00:00');
    $this->save = fn (?Branch $shop, array $values) => app(SaveShopSettings::class)->handle($this->company, $shop, $values);
    $this->settings = fn (TestResponse $reply) => collect(Pull::changes($reply))->where('entity', 'Setting')->values();
    $this->manager = $this->memberOf($this->company, CompanyRole::Manager);
});

test('the catalogue holds only shared keys of the contract, never a deny-listed one, each with a known type', function () {
    $file = json_decode((string) file_get_contents(base_path('docs/contracts/portal-api-v1.4.1/docs/web-portal-api/samples/settings-local-only.json')), true);

    foreach (SettingCatalogue::all() as $key => $definition) {
        expect($file['sharedKeys'])->toContain($key)
            ->and($file['localOnlyKeys'])->not->toContain($key)
            ->and(SettingSyncPolicy::isLocalOnly('company', $key))->toBeFalse()
            ->and(SettingCatalogue::TYPES)->toContain($definition['type'])
            ->and($definition['label'])->not->toBe('')->and($definition['help'])->not->toBe('');
    }

    expect(SettingCatalogue::all())->toHaveKeys(['receipt.header_lines', 'receipt.footer_text', 'shop.vat_number', 'shop.trading_hours', 'cash.count_on_close', 'till.keypad_price_in_pence', 'compliance.challenge25_age', 'customers.loyalty_points_per_pound']);
});

test('every-shop settings are normalised to the till\'s text and reach every till; a shop\'s own reach that shop only', function () {
    $changed = ($this->save)(null, ['receipt.footer_text' => "Thank you!\r\nSee you soon  ", 'cash.count_on_close' => true, 'till.bag_charge_amount' => '£0.1', 'compliance.challenge25_age' => ' 25 ', 'customers.loyalty_pounds_per_point' => '0.0100']);
    expect($changed)->toHaveCount(5);

    foreach ([false, true] as $bradford) {
        $sent = ($this->settings)($this->sync->pull(0, bradford: $bradford)->assertOk());
        expect($sent->pluck('payload.value', 'payload.key')->all())->toEqual([
            'receipt.footer_text' => "Thank you!\nSee you soon", 'cash.count_on_close' => 'true', 'till.bag_charge_amount' => '0.10',
            'compliance.challenge25_age' => '25', 'customers.loyalty_pounds_per_point' => '0.01',
        ])->and($sent->pluck('payload.scope')->unique()->all())->toBe(['company']);
    }

    ($this->save)($this->sync->leeds, ['receipt.footer_text' => 'Leeds says thanks', 'till.refund_needs_manager' => 'true']);
    $leeds = ($this->settings)($this->sync->pull(0));
    expect($leeds->where('payload.scope', 'branch')->pluck('payload.value', 'payload.key')->all())->toBe(['receipt.footer_text' => 'Leeds says thanks', 'till.refund_needs_manager' => 'true'])
        ->and($leeds->where('payload.scope', 'branch')->pluck('branchId')->unique()->all())->toBe([TillFixtures::LEEDS])
        ->and(($this->settings)($this->sync->pull(0, bradford: true))->where('payload.scope', 'branch'))->toHaveCount(0);
});

test('the same value again changes nothing; a blank removes the setting so the wider one applies (D)', function () {
    ($this->save)($this->sync->leeds, ['receipt.copies' => '2']);
    $since = collect(Pull::changes($this->sync->pull(0)))->max('version');

    expect(($this->save)($this->sync->leeds, ['receipt.copies' => 2]))->toBe([])
        ->and(($this->settings)($this->sync->pull($since)))->toHaveCount(0);

    expect(($this->save)($this->sync->leeds, ['receipt.copies' => '']))->toBe(['receipt.copies']);
    $removed = ($this->settings)($this->sync->pull($since))->sole();
    expect([$removed['op'], $removed['payload']['key'], $removed['branchId']])->toBe(['D', 'receipt.copies', TillFixtures::LEEDS])
        ->and(AuditLog::query()->where('action', 'till_settings.updated')->count())->toBe(2);
});

test('bad values and keys outside the catalogue are refused, deny-listed ones always', function (string $key, mixed $value) {
    expect(fn () => ($this->save)(null, [$key => $value]))->toThrow(ValidationException::class);
    expect(TillSetting::withoutCompanyScope()->count())->toBe(0);
})->with([
    'not on/off' => ['cash.count_on_close', 'maybe'],
    'three decimals of money' => ['till.bag_charge_amount', '0.105'],
    'over the maximum' => ['receipt.copies', '9'],
    'negative' => ['cash.default_float', '-5'],
    'text too long' => ['shop.vat_number', str_repeat('9', 21)],
    'not in the catalogue' => ['till.tile_size', 'large'],
    'sync' => ['sync.hub_url', 'https://evil.example'],
    'device' => ['printers.receipt_printer', 'EPSON'],
    'secret' => ['payments.dojo_api_key', 'sk_live'],
    'local display' => ['display.theme', 'dark'],
    'bookkeeping' => ['retention.last_vacuum_utc', '2026-01-01'],
]);

test('the page shows every-shop values with the shops that differ, and a shop\'s own values with what it falls back to', function () {
    ($this->save)(null, ['receipt.footer_text' => 'Thanks', 'cash.count_on_close' => 'true']);
    ($this->save)($this->sync->leeds, ['cash.count_on_close' => 'false']);

    $this->actingAs($this->manager)->get('/app/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/settings/index')->where('shop', null)->where('canEveryShop', true)->has('shops', 2)
        ->where('values', ['receipt.footer_text' => 'Thanks', 'cash.count_on_close' => 'true'])
        ->where('overrides', ['cash.count_on_close' => ['Leeds Kirkgate']])
        ->has('sections', count(SettingCatalogue::sections())));

    $this->actingAs($this->manager)->get("/app/settings?shop={$this->sync->leeds->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('shop.id', $this->sync->leeds->id)->where('values', ['cash.count_on_close' => 'false'])
        ->where('inherited', fn ($inherited) => $inherited['receipt.footer_text'] === ['value' => 'Thanks', 'from' => 'everyShop']
            && $inherited['till.manager_pin_refund_over'] === ['value' => '0.00', 'from' => 'default']
            && $inherited['receipt.copies'] === ['value' => null, 'from' => 'default']));

    $this->actingAs($this->manager)->put('/app/settings', ['shop' => $this->sync->bradford->id, 'values' => ['receipt.footer_text' => 'Bradford thanks you']])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
    $this->actingAs($this->manager)->put('/app/settings', ['shop' => null, 'values' => ['receipt.copies' => 'lots']])->assertSessionHasErrors('values.receipt.copies');
    expect(TillSetting::withoutCompanyScope()->where('scope_id', $this->sync->bradford->id)->sole()->value)->toBe('Bradford thanks you');
});

test('guests go to the login; staff and accountants get 403', function () {
    $this->get('/app/settings')->assertRedirect('/login');
    $this->put('/app/settings', ['values' => ['receipt.copies' => '1']])->assertRedirect('/login');

    foreach ([CompanyRole::Staff, CompanyRole::Accountant] as $role) {
        $user = $this->memberOf($this->company, $role);
        $this->actingAs($user)->get('/app/settings')->assertForbidden();
        $this->actingAs($user)->put('/app/settings', ['values' => ['receipt.copies' => '1']])->assertForbidden();
    }

    expect(TillSetting::withoutCompanyScope()->count())->toBe(0);
});

test('a one-shop manager sees and changes only their own shop\'s settings', function () {
    $this->company->users()->updateExistingPivot($this->manager->id, ['branch_id' => $this->sync->leeds->id]);

    $this->actingAs($this->manager)->get('/app/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('shop.id', $this->sync->leeds->id)->where('canEveryShop', false)->has('shops', 1));
    $this->actingAs($this->manager)->get("/app/settings?shop={$this->sync->bradford->id}")->assertForbidden();
    $this->actingAs($this->manager)->put('/app/settings', ['shop' => null, 'values' => ['receipt.copies' => '1']])->assertForbidden();
    $this->actingAs($this->manager)->put('/app/settings', ['shop' => $this->sync->bradford->id, 'values' => ['receipt.copies' => '1']])->assertForbidden();
    $this->actingAs($this->manager)->put('/app/settings', ['shop' => $this->sync->leeds->id, 'values' => ['receipt.copies' => '1']])->assertSessionHasNoErrors();

    expect(TillSetting::withoutCompanyScope()->sole()->only(['scope_id', 'setting_key', 'value']))
        ->toBe(['scope_id' => $this->sync->leeds->id, 'setting_key' => 'receipt.copies', 'value' => '1']);
});

test('another business cannot see or change our shops\' settings, and theirs never show here', function () {
    ($this->save)(null, ['receipt.footer_text' => 'Ours']);
    $other = Company::factory()->create();
    $theirOwner = $this->memberOf($other, CompanyRole::Owner);
    app(SaveTillSetting::class)->handle($other, SettingScope::Company, null, 'receipt.copies', '3');

    $this->actingAs($theirOwner)->get('/app/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('values', ['receipt.copies' => '3'])->has('shops', 0));
    $this->actingAs($theirOwner)->get("/app/settings?shop={$this->sync->leeds->id}")->assertNotFound();
    $this->actingAs($theirOwner)->put('/app/settings', ['shop' => $this->sync->leeds->id, 'values' => ['receipt.footer_text' => 'Hijacked']])->assertSessionHasErrors('shop');
    expect(fn () => app(SaveShopSettings::class)->handle($other, $this->sync->leeds, ['receipt.footer_text' => 'Hijacked']))->toThrow(ValidationException::class);

    $this->actingAs($this->memberOf($this->company, CompanyRole::Owner))->get('/app/settings')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('values', ['receipt.footer_text' => 'Ours']));
    expect(TillSetting::withoutCompanyScope()->where('value', 'Hijacked')->exists())->toBeFalse();
});
