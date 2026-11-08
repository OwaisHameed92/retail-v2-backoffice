<?php

use App\Domain\Customers\Support\CustomerFormat;
use App\Domain\Salon\Support\SalonApiErrors;
use App\Domain\Salon\Support\SalonRights;
use App\Domain\ShopSettings\Actions\SaveShopSettings;
use App\Domain\ShopSettings\Queries\ShopSettingsPage;
use App\Domain\ShopSettings\Support\VerticalSettings;
use App\Domain\Staff\Support\TillPermissionCatalogue;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\TillData\Actions\SaveTillSetting;
use App\Domain\TillData\Enums\ConsentSource;
use App\Domain\TillData\Enums\SettingScope;
use App\Domain\TillData\Sync\SettingSyncPolicy;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sync\SalonRows as Rows;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Salon pack 2026-11-08 (W2): the salon's pushed settings shown read-only in Salon sections, salon shops only; the
 * portal never writes `salon.*` or `vertical.salon`; words for salon rights (when sent) and audit actions; the salon
 * calls' error codes recorded for W3.
 */

beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->page = fn () => app(CurrentCompany::class)->runAs($this->company, fn () => ShopSettingsPage::for($this->company->refresh(), $this->sync->leeds, null));
    $this->sections = fn (array $page) => array_column($page['sections'], 'id');
    $this->errors = function (Closure $call): array {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };
    $this->salonSections = ['salon', 'salonDiary', 'salonMessages', 'salonOnline'];
});

it('shows a grocery no salon section', function () {
    expect(array_intersect(($this->sections)(($this->page)()), $this->salonSections))->toBe([]);
});

it('shows a salon its pushed salon settings read-only, never a secret', function () {
    $this->sync->push([
        Rows::setting('vertical.salon', 'true', 1),
        Rows::setting('salon.staff_word', 'Barber', 2),
        Rows::setting('salon.diary.auto_accept_online', 'true', 3),
        Rows::setting('salon.online.booking_url', 'https://book.example.co.uk/kings-cuts', 4),
        Rows::setting('salon.integrations.sms_secret', 'twilio-secret', 5),
    ])->assertOk();

    $page = ($this->page)();
    $sections = collect($page['sections'])->whereIn('id', $this->salonSections);
    $keys = $sections->flatMap(fn (array $s) => array_column($s['settings'], 'key'));

    expect(($this->sections)($page))->toContain('salon', 'salonDiary', 'salonMessages', 'salonOnline', 'shopType')
        ->and($sections->flatMap(fn (array $s) => $s['settings'])->every(fn (array $s) => $s['readOnly'] === true))->toBeTrue()
        ->and($keys)->not->toContain('salon.integrations.sms_secret', 'salon.online.manage_link_key', 'salon.queue.board_on')
        ->and((array) $page['values'])->toMatchArray([
            'salon.staff_word' => 'Barber', 'salon.diary.auto_accept_online' => 'true',
            'salon.online.booking_url' => 'https://book.example.co.uk/kings-cuts', 'vertical.salon' => 'true',
        ])
        ->and((string) json_encode($page))->not->toContain('twilio-secret');

    expect(($this->errors)(fn () => app(SaveShopSettings::class)->handle($this->company, $this->sync->leeds, ['salon.staff_word' => 'Stylist'])))->toHaveKey('values.salon.staff_word');
});

it('knows a salon by its business type too', function () {
    $this->company->forceFill(['business_type' => BusinessType::Salon])->save();

    expect(($this->sections)(($this->page)()))->toContain('salon', 'salonOnline');
});

it('lists every salon key a till shares, and only those', function () {
    $file = TillFixtures::sample('settings-local-only.json');
    $shared = array_values(array_filter($file['sharedKeys'], fn (string $k) => str_starts_with($k, 'salon.')));
    $listed = collect(VerticalSettings::catalogue())->only($this->salonSections)->flatMap(fn (array $s) => array_keys($s['settings']))->sort()->values()->all();
    sort($shared);

    expect($listed)->toBe($shared)
        ->and(count($shared))->toBe(58)
        ->and(in_array('vertical.salon', $file['sharedKeys'], true))->toBeTrue()
        ->and(SettingSyncPolicy::isLocalOnly('branch', 'salon.online.manage_link_key'))->toBeTrue()
        ->and(SettingSyncPolicy::isLocalOnly('branch', 'salon.integrations.meta_access_token'))->toBeTrue()
        ->and(SettingSyncPolicy::isLocalOnly('branch', 'salon.queue.checkin_on'))->toBeTrue();
});

it('never writes a salon setting or the salon shop type from the portal', function (string $key) {
    $save = fn () => app(SaveTillSetting::class)->handle($this->company, SettingScope::Branch, $this->sync->leeds, $key, 'true');

    expect(($this->errors)($save))->toHaveKey('key')
        ->and(SettingSyncPolicy::tillWritesOnly($key))->toBeTrue();
})->with(['vertical.salon', 'salon.online.enabled', 'salon.diary.auto_accept_online', 'salon.tips.ask']);

it('gives salon rights words when a salon till sends them, and offers them to no other shop', function () {
    $groups = fn () => app(CurrentCompany::class)->runAs($this->company, fn () => collect(app(TillPermissionCatalogue::class)->groups())->pluck('permissions', 'label'));
    $readme = (string) file_get_contents(base_path(TillFixtures::SALON.'/README-PHARMACY-BIKEBOOK-SALON.md'));
    preg_match('/RolePermission` keys[^|]*\|([^\n]+)/', $readme, $m);
    preg_match_all('/`(salon\.[a-z_]+)`/', $m[1] ?? '', $keys);

    expect($keys[1])->toHaveCount(11)
        ->and(array_keys(SalonRights::WORDS))->toEqualCanonicalizing($keys[1])
        ->and($groups()->keys())->not->toContain('Salon');

    $this->sync->push([Rows::right('01K5T0Q8C4000000000000G002', 'salon.tips_manage', 1), Rows::right('01K5T0Q8C4000000000000G002', 'salon.queue_reorder', 2)])->assertOk();

    expect(collect($groups()['Salon'])->pluck('label', 'key')->all())->toBe(['salon.queue_reorder' => 'Reorder the queue', 'salon.tips_manage' => 'Pay out tips']);
});

it('has words for every salon audit action', function () {
    $readme = (string) file_get_contents(base_path(TillFixtures::SALON.'/README-PHARMACY-BIKEBOOK-SALON.md'));
    preg_match('/\*\*New AuditLog actions\*\*([^\n]+)/', $readme, $m);
    preg_match_all('/`(Salon[A-Za-z.]*)`/', $m[1], $names);
    // "`SalonRenterStatementIssue` / `Settle` / `Cancel`", and the Messages line's test send (UPCOMING-CHANGES).
    $actions = [...$names[1], 'SalonRenterStatementSettle', 'SalonRenterStatementCancel', 'SalonMessageTest'];
    $words = (string) file_get_contents(resource_path('js/components/app/sales/salon-words.ts'));
    preg_match_all("/^\s+'?([A-Za-z.]+)'?: '/m", $words, $labelled);

    expect($actions)->toHaveCount(39)
        ->and($labelled[1])->toEqualCanonicalizing($actions)
        ->and((string) file_get_contents(resource_path('js/components/app/sales/format.tsx')))->toContain('...SALON_AUDIT_ACTIONS');
});

it('records the salon calls\' error codes exactly as the pack lists them', function () {
    $pack = json_decode((string) file_get_contents(base_path(TillFixtures::SALON.'/salon/error-codes.additions.json')), true);

    expect(SalonApiErrors::STATUS)->toBe(array_column($pack, 'status', 'code'));

    foreach ([SalonApiErrors::notAvailable(), SalonApiErrors::notASalon(), SalonApiErrors::pageTooLarge(), SalonApiErrors::joinNotFound('01K9SAJN000000000000000001')] as $error) {
        expect($error->httpStatus)->toBe(SalonApiErrors::STATUS[$error->errorCode]);
    }

    expect(SalonApiErrors::notAvailable()->retryAfterSeconds)->toBe(3600)
        ->and(SalonApiErrors::joinNotFound('01K9SAJN000000000000000001')->rejectedKey)->toBe('01K9SAJN000000000000000001');
});

it('words the new consent sources and cash movements', function () {
    expect(CustomerFormat::sourceLabel(ConsentSource::OnlineBooking))->toBe('Online booking')
        ->and(CustomerFormat::sourceLabel(ConsentSource::Import))->toBe('Imported from another salon system')
        ->and(array_map(fn (ConsentSource $s) => CustomerFormat::sourceLabel($s), ConsentSource::cases()))->not->toContain('Not recorded')
        ->and((string) file_get_contents(resource_path('js/components/app/cash/format.tsx')))->toContain("tipTaken: 'Tips taken'", "tipPayout: 'Tips paid out'");
});
