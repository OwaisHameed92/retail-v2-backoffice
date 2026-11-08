<?php

use App\Domain\Licensing\Api\Support\LicenceToken;
use App\Domain\Licensing\Enums\AddOn;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Workshop\Actions\SetWorkshopAddOn;
use Carbon\CarbonImmutable;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * The paid feature `workshop_integration` (WORKSHOP-BIKEBOOK.md §6, licensing/schemas/common.schema.json): a per-shop
 * add-on appended to the licence token's open `features` list. The plan Feature enum keeps the till's 11 names, so no
 * plan or existing licence changes; a shop without the add-on gets exactly the token it had.
 */

beforeEach(function () {
    $this->travelTo('2026-11-08 09:00:00');
    $this->sync = new SyncApiFixtures($this);
});

function claimsOf(Licence $licence): array
{
    $now = CarbonImmutable::now();

    return app(LicenceToken::class)->claims($licence->refresh(), LicenceState::for($licence, $now), $now)->features;
}

test('the plan features stay the till\'s 11; the add-on is its own list with the till\'s feature name', function () {
    expect(Feature::values())->toHaveCount(11)->not->toContain('workshop_integration')
        ->and(AddOn::WorkshopIntegration->value)->toMatch('/^[a-z0-9]+([._-][a-z0-9]+)*$/');
});

test('without the add-on the token features are exactly as before; ticking it appends workshop_integration; taking it off removes it', function () {
    $licence = Licence::factory()->forRegister($this->sync->tills[T::TILL_1])->paid(30)->create(['features' => [Feature::CloudSync->value, Feature::Loyalty->value]]);
    $before = claimsOf($licence);
    $stored = $licence->features->map->value->all();
    $fingerprint = LicenceToken::fingerprint(app(LicenceToken::class)->claims($licence, LicenceState::for($licence, CarbonImmutable::now()), CarbonImmutable::now()));

    app(SetWorkshopAddOn::class)->handle($this->sync->leeds, true);
    expect(claimsOf($licence))->toBe([...$before, 'workshop_integration'])
        ->and($licence->refresh()->features->map->value->all())->toBe($stored);

    // Bradford's till never gets it.
    $bradford = Licence::factory()->forRegister($this->sync->tills[T::BRADFORD_TILL])->paid(30)->create(['features' => [Feature::CloudSync->value]]);
    expect(claimsOf($bradford))->not->toContain('workshop_integration');

    app(SetWorkshopAddOn::class)->handle($this->sync->leeds, false);
    expect(claimsOf($licence))->toBe($before)
        ->and(LicenceToken::fingerprint(app(LicenceToken::class)->claims($licence, LicenceState::for($licence, CarbonImmutable::now()), CarbonImmutable::now())))->toBe($fingerprint)
        ->and($this->sync->leeds->refresh()->licence_add_ons)->toBeNull();
});

test('a trial token with no features means every feature on: the add-on is not appended (a list would switch the rest off)', function () {
    $licence = Licence::factory()->forRegister($this->sync->tills[T::TILL_1])->trial()->create(['features' => []]);
    $before = claimsOf($licence);
    app(SetWorkshopAddOn::class)->handle($this->sync->leeds, true);

    expect(claimsOf($licence))->toBe($before)->not->toContain('workshop_integration');
});
