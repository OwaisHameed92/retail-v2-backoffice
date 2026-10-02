<?php

use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use App\Domain\TillData\Sync\SettingSyncPolicy;
use App\Domain\TillData\Sync\SyncRowIds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\TillData\TillFixtures;

/** Contract v1.4.1 §10.3: Setting and RolePermission are keyed rows; the deny-list and `local` tables are never stored. */
beforeEach(function () {
    [$this->company, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->apply = fn (array $changes, $branch = null) => TillFixtures::apply($this->company, $branch ?? $this->leeds, $changes);
    $this->setting = fn (int $seq, array $payload, array $envelope = []) => [
        'seq' => $seq, 'entity' => 'Setting', 'entityId' => SyncRowIds::setting($payload['scope'], $payload['scopeId'], $payload['key']),
        'op' => 'U', 'version' => $seq, 'companyId' => TillFixtures::COMPANY, 'branchId' => '', 'registerId' => '',
        'at' => $payload['updatedAt'], 'payload' => $payload, 'key' => "Setting:{$seq}", ...$envelope,
    ];
});

it('derives the till\'s keyed-row ids (SyncRowIds) exactly as the settings sample does', function () {
    $sample = TillFixtures::sample('push-request.settings.json');

    expect(SyncRowIds::setting('branch', TillFixtures::LEEDS, 'receipt.footer_text'))->toBe($sample[0]['entityId'])
        ->and(SyncRowIds::rolePermission('01K5T0Q8C4000000000000G002', 'sale.refund'))->toBe($sample[1]['entityId'])
        ->and(SyncRowIds::rolePermission('01K5T0Q8C4000000000000G002', 'sale.no_sale'))->toBe($sample[2]['entityId']);
});

it('replays push-request.settings.json: a branch setting, a permission granted and one taken away', function () {
    $result = ($this->apply)(TillFixtures::sample('push-request.settings.json'));

    expect(TillFixtures::ack($result))->toBe(['acknowledgedSeq' => 18263, 'accepted' => 3])
        ->and($result->rejected)->toBe([])
        ->and($result->count(ChangeOutcome::Applied))->toBe(3);

    $setting = DB::table('till_settings')->sole();
    expect($setting->id)->toBe('D9FR992FKMEN9BGPG7H9MME95C')
        ->and($setting->scope)->toBe('branch')
        ->and($setting->scope_id)->toBe(TillFixtures::LEEDS)
        ->and($setting->setting_key)->toBe('receipt.footer_text')
        ->and($setting->value)->toBe('Thank you for shopping at Kirkgate!')
        ->and($setting->row_version)->toBe(18261)
        ->and($setting->origin_branch_id)->toBe(TillFixtures::LEEDS)
        ->and($setting->hub_version)->toBeNull();

    $permissions = DB::table('till_role_permissions')->orderBy('permission_key')->get()->keyBy('permission_key');
    expect($permissions->keys()->all())->toBe(['sale.no_sale', 'sale.refund'])
        ->and($permissions['sale.refund']->deleted_at)->toBeNull()
        ->and($permissions['sale.refund']->updated_at)->toBe('2026-09-23 09:53:12')
        ->and($permissions['sale.no_sale']->deleted_at)->not->toBeNull()
        ->and($permissions['sale.no_sale']->role_id)->toBe('01K5T0Q8C4000000000000G002');

    // A retry is a duplicate; a later grant brings a removed permission back.
    $again = ($this->apply)(TillFixtures::sample('push-request.settings.json'));
    $sample = TillFixtures::sample('push-request.settings.json')[2];
    $regrant = ($this->apply)([[...$sample, 'seq' => 18270, 'version' => 18270, 'op' => 'I', 'at' => '2026-09-24T09:00:00Z']]);

    expect($again->count(ChangeOutcome::Duplicate))->toBe(3)
        ->and($regrant->count(ChangeOutcome::Applied))->toBe(1)
        ->and(DB::table('till_role_permissions')->where('permission_key', 'sale.no_sale')->value('deleted_at'))->toBeNull();
});

it('keys a setting by its payload: one company setting from two shops is one row, the later change wins', function () {
    $company = ['scope' => 'company', 'scopeId' => '', 'key' => 'receipt.header_text', 'value' => 'Khan Stores', 'updatedAt' => '2026-09-23T10:00:00Z'];

    ($this->apply)([($this->setting)(40, $company)]);
    ($this->apply)([($this->setting)(7, [...$company, 'value' => 'Khan Stores Ltd', 'updatedAt' => '2026-09-23T11:00:00Z'], ['entityId' => 'ZZZZZZZZZZZZZZZZZZZZZZZZZZ'])], $this->bradford);
    $older = ($this->apply)([($this->setting)(41, [...$company, 'value' => 'Old', 'updatedAt' => '2026-09-23T10:30:00Z'])]);

    $row = DB::table('till_settings')->sole();
    expect($row->id)->toBe(SyncRowIds::setting('company', TillFixtures::COMPANY, 'receipt.header_text'))
        ->and($row->scope_id)->toBe(TillFixtures::COMPANY)
        ->and($row->value)->toBe('Khan Stores Ltd')
        ->and($row->origin_branch_id)->toBe(TillFixtures::BRADFORD)
        ->and($older->count(ChangeOutcome::Stale))->toBe(1);
});

it('refuses a setting of another shop or business', function () {
    $branch = ['scope' => 'branch', 'scopeId' => TillFixtures::BRADFORD, 'key' => 'till.refund_needs_manager', 'value' => 'true', 'updatedAt' => '2026-09-23T10:00:00Z'];
    $company = ['scope' => 'company', 'scopeId' => '01K5T0Q8C4000000000000C009', 'key' => 'receipt.header_text', 'value' => 'x', 'updatedAt' => '2026-09-23T10:00:00Z'];

    $result = ($this->apply)([($this->setting)(1, $branch), ($this->setting)(2, $company)]);

    expect(collect($result->rejected)->pluck('code')->all())->toBe(['sync.wrong_branch', 'sync.wrong_company'])
        ->and(DB::table('till_settings')->count())->toBe(0);
});

it('never stores a deny-listed or register-scope setting: acknowledged, skipped, the value never logged', function () {
    Log::spy();
    $rows = [
        ['scope' => 'company', 'scopeId' => '', 'key' => 'payments.dojo_api_key', 'value' => 'sk_live_SECRET1', 'updatedAt' => '2026-09-23T10:00:00Z'],
        ['scope' => 'branch', 'scopeId' => '', 'key' => 'messaging.smtp_password', 'value' => 'SECRET2', 'updatedAt' => '2026-09-23T10:00:00Z'],
        ['scope' => 'branch', 'scopeId' => '', 'key' => 'backup.last_vacuum_utc', 'value' => '2026-09-23T10:00:00Z', 'updatedAt' => '2026-09-23T10:00:00Z'],
        ['scope' => 'register', 'scopeId' => TillFixtures::TILL_1, 'key' => 'receipt.footer_text', 'value' => 'SECRET3', 'updatedAt' => '2026-09-23T10:00:00Z'],
        ['scope' => 'branch', 'scopeId' => '', 'key' => 'till_ease.big_text', 'value' => 'true', 'updatedAt' => '2026-09-23T10:00:00Z'],
        ['scope' => 'branch', 'scopeId' => '', 'key' => 'receipt.footer_text', 'value' => 'Thanks!', 'updatedAt' => '2026-09-23T10:00:00Z'],
    ];

    $result = ($this->apply)(array_map(fn (array $payload, int $i) => ($this->setting)($i + 1, $payload), $rows, array_keys($rows)));

    expect(TillFixtures::ack($result))->toBe(['acknowledgedSeq' => 6, 'accepted' => 6])
        ->and($result->count(ChangeOutcome::Skipped))->toBe(5)
        ->and(DB::table('till_settings')->pluck('setting_key')->all())->toBe(['receipt.footer_text'])
        ->and(DB::table('sync_applied_changes')->count())->toBe(1);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => ! str_contains((string) json_encode($context), 'SECRET')
        && isset($context['rows']['Setting payments.dojo_api_key']))->once();
});

it('skips per-user screen settings whose envelope carries a user id as companyId, so the queue never stalls (EPOS 2026-10-02)', function () {
    $userId = '01M3YKNDEJKKVD6KBE44KGVVPF';
    $rows = [
        ['scope' => 'company', 'scopeId' => $userId, 'key' => 'grid.layout.products', 'value' => '{"cols":4}', 'updatedAt' => '2026-10-02T15:30:00Z'],
        ['scope' => 'company', 'scopeId' => $userId, 'key' => 'help.tour_dismissed.sales', 'value' => 'true', 'updatedAt' => '2026-10-02T15:30:00Z'],
    ];
    $changes = array_map(fn (array $payload, int $i) => ($this->setting)($i + 1, $payload, ['companyId' => $userId]), $rows, array_keys($rows));
    $changes[] = ($this->setting)(3, ['scope' => 'branch', 'scopeId' => '', 'key' => 'receipt.footer_text', 'value' => 'Thanks!', 'updatedAt' => '2026-10-02T15:30:00Z']);

    $result = ($this->apply)($changes);

    expect(TillFixtures::ack($result))->toBe(['acknowledgedSeq' => 3, 'accepted' => 3])
        ->and($result->count(ChangeOutcome::Skipped))->toBe(2)
        ->and(DB::table('till_settings')->pluck('setting_key')->all())->toBe(['receipt.footer_text']);
});

it('still refuses an ordinary setting sent with another company id', function () {
    $row = ['scope' => 'company', 'scopeId' => '', 'key' => 'receipt.header_text', 'value' => 'x', 'updatedAt' => '2026-10-02T15:30:00Z'];

    $result = ($this->apply)([($this->setting)(1, $row, ['companyId' => '01M3YKNDEJKKVD6KBE44KGVVPF'])]);

    expect(collect($result->rejected)->pluck('code')->all())->toBe(['sync.wrong_company']);
});

it('acknowledges rows of a `local` table (an older till\'s SyncState) without storing them', function () {
    $state = ['entity' => 'Sale', 'lastPushedSeq' => 10, 'id' => '01K5T0Q8C4000000000000S001', 'companyId' => TillFixtures::COMPANY, 'createdAt' => '2026-09-23T09:00:00Z', 'updatedAt' => '2026-09-23T09:00:00Z', 'rowVersion' => 1, 'deletedAt' => null];
    $before = DB::table('till_sync_states')->count();

    $result = ($this->apply)([TillFixtures::envelope('SyncState', $state, 1)]);

    expect(TillFixtures::ack($result))->toBe(['acknowledgedSeq' => 1, 'accepted' => 1])
        ->and($result->count(ChangeOutcome::Skipped))->toBe(1)
        ->and(DB::table('till_sync_states')->count())->toBe($before);
});

it('keeps SettingSyncPolicy::LOCAL_KEYS exactly the file\'s localOnlyKeys that no rule catches (drift either way fails)', function () {
    $file = TillFixtures::sample('settings-local-only.json');
    $missedByRules = array_values(array_filter($file['localOnlyKeys'], fn (string $key) => ! SettingSyncPolicy::caughtByRules($key)));
    sort($missedByRules);

    // ANSWERS-2026-09-29-b: five more till-only keys; cash.count_on_close and till.keypad_price_in_pence stay shared.
    expect(SettingSyncPolicy::LOCAL_KEYS)->toBe($missedByRules)
        ->toContain('receipt.print_switch', 'till.beep_on_add', 'till.beep_on_not_found', 'till.popup_keyboard', 'till_ease.simple_mode')
        ->and(array_intersect(SettingSyncPolicy::LOCAL_KEYS, $file['sharedKeys']))->toBe([])
        ->and($file['sharedKeys'])->toContain('cash.count_on_close', 'till.keypad_price_in_pence');
});

it('keeps SettingSyncPolicy equal to samples/settings-local-only.json', function () {
    $file = TillFixtures::sample('settings-local-only.json');

    expect(SettingSyncPolicy::LOCAL_SCOPES)->toBe($file['localOnlyScopes'])
        ->and(SettingSyncPolicy::LOCAL_PREFIXES)->toBe($file['localOnlyPrefixes'])
        ->and(SettingSyncPolicy::SECRET_WORDS)->toBe($file['secretWords'])
        ->and(SettingSyncPolicy::SECRET_ENDINGS)->toBe($file['secretEndings'])
        ->and(SettingSyncPolicy::BOOKKEEPING_ENDINGS)->toBe($file['bookkeepingEndings']);

    foreach ($file['localOnlyKeys'] as $key) {
        expect(SettingSyncPolicy::isLocalOnly('branch', $key))->toBeTrue($key);
    }

    foreach ($file['sharedKeys'] as $key) {
        expect(SettingSyncPolicy::isLocalOnly('company', $key))->toBeFalse($key)
            ->and(SettingSyncPolicy::isLocalOnly('register', $key))->toBeTrue($key);
    }
});
