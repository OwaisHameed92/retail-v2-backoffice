<?php

use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Enums\CashMovementType;
use App\Domain\TillData\Enums\ConsentSource;
use App\Domain\TillData\Models\CashMovement;
use App\Domain\TillData\Models\Consent;
use App\Domain\TillData\Models\SalonSaleLineStaff;
use App\Domain\TillData\Models\SalonTip;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Sync\SalonRows as Rows;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Sync\Till0185Rows;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\ContractSchema;

/*
 * Salon pack 2026-11-08 (W2; docs/contracts/salon-barber-2026-11-08, README Part C "sync changes"): a salon's till
 * pushes rows of 40 new Salon* tables and new list values through the ordinary `POST sync/push`; every one is stored,
 * never refused. Each envelope is first checked against the salon pack's schemas.
 */

beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->travelTo('2026-11-08 10:00:00');
    $this->push = function (array $changes) {
        foreach ($changes as $i => $change) {
            expect(ContractSchema::changeErrors($change, "changes[{$i}]"))->toBe([]);
        }

        $reply = $this->sync->push($changes)->assertOk();
        expect(SyncApiFixtures::schemaErrors($reply, 'push-reply.schema.json'))->toBe([])
            ->and($reply->json('accepted'))->toBe(count($changes))
            ->and($reply->json('acknowledgedSeq'))->toBe(max(array_column($changes, 'seq')));

        return $reply;
    };
});

it('stores a row of every one of the 40 Salon* tables, schema-valid, with the till\'s ids as sent', function () {
    $entities = Rows::entities();
    expect($entities)->toHaveCount(40)->not->toContain('SalonClientMedical');

    $changes = array_map(fn (string $entity, int $i) => Rows::envelope($entity, Rows::id($entity), $i + 1), $entities, array_keys($entities));
    ($this->push)($changes);

    foreach ($entities as $entity) {
        $def = EntityRegistry::get($entity);
        $row = (array) DB::table($def->table)->where('id', Rows::id($entity))->first();

        expect($row['company_id'] ?? null)->toBe($this->sync->company->id, $entity)
            ->and(str_starts_with($def->table, 'salon_'))->toBeTrue($entity);

        if ($def->hasScopeColumn('branch_id')) {
            expect($row['branch_id'])->toBe($this->sync->leeds->id, $entity);
        }
    }

    // Money 2 dp, costs / quantities 4 dp, rates 4 dp.
    $staff = SalonSaleLineStaff::withoutCompanyScope()->findOrFail(Rows::id('SalonSaleLineStaff'));
    expect([$staff->net_amount, $staff->cost_amount, $staff->quantity, $staff->share_percent])->toBe(['1.50', '1.5000', '1.5000', '1.5000'])
        ->and(EntityRegistry::get('SalonTip')->ownership)->toBe('branch')
        ->and(EntityRegistry::get('SalonLevel')->ownership)->toBe('hub')
        ->and(EntityRegistry::get('SalonOnlineBooking')->scope)->toBe('branch')
        ->and(EntityRegistry::get('SalonTip')->scope)->toBe('register');
});

it('stores the online request samples of the pack (new, accepted, preferences)', function () {
    $new = Rows::onlineRequest('new');
    $preferences = Rows::onlineRequest('preferences');

    ($this->push)([
        TillFixtures::envelope('SalonOnlineBooking', $new, 1, ['version' => 1]),
        TillFixtures::envelope('SalonOnlineBooking', Rows::onlineRequest('accepted'), 2),
        TillFixtures::envelope('SalonOnlineBooking', $preferences, 3, ['version' => 1]),
    ]);

    $row = (array) DB::table('salon_online_bookings')->where('id', $new['id'])->first();
    expect($row)->toMatchArray(['status' => 'accepted', 'appointment_id' => '01K9S0000000000000000AP001', 'reference' => 'WEB-000123', 'branch_id' => $this->sync->leeds->id])
        ->and((string) $row['services_json'])->toBe($new['servicesJson'])
        ->and(DB::table('salon_online_bookings')->where('id', $preferences['id'])->value('kind'))->toBe('preferences');
});

it('stores cash tips, online and imported consents, the salon accounts and journal types', function () {
    $account = fn (string $code, string $name, string $type, ?int $box, int $seq) => Till0185Rows::envelope('Account', '01K9SAAC00000000000000'.$code, $seq, [
        'code' => $code, 'name' => $name, 'type' => $type, 'parentCode' => null, 'vatBox' => $box, 'isSystem' => true, 'isActive' => true, 'isDebitBalance' => false,
    ]);
    $movement = fn (string $id, string $type, float $amount, int $seq) => Till0185Rows::envelope('CashMovement', $id, $seq, [
        'type' => $type, 'amount' => $amount, 'branchId' => TillFixtures::LEEDS, 'registerId' => TillFixtures::TILL_1, 'shiftId' => '01K9SASH000000000000000001', 'reasonId' => '', 'note' => '',
    ]);
    $consent = fn (string $id, string $source, int $seq) => Till0185Rows::envelope('Consent', $id, $seq, [
        'customerId' => '01K9S0000000000000000CU001', 'channel' => 'sms', 'given' => true, 'source' => $source, 'withdrawnAt' => null, 'isActive' => true,
    ]);
    $journals = array_map(fn (string $type, int $i) => Till0185Rows::envelope('JournalEntry', sprintf('01K9SAJE%018d', $i), 20 + $i, ['refType' => $type, 'refId' => 'x', 'memo' => $type, 'totalDebits' => 1, 'totalCredits' => 1]),
        $types = ['SalonTip', 'SalonTipPayout', 'SalonRenterTakings', 'SalonRenterStatement', 'SalonRenterSettlement', 'SalonPackageSold', 'SalonPackageRelease'], array_keys($types));

    ($this->push)([
        $account('2280', 'Tips payable', 'liability', null, 1),
        $account('2290', 'Chair renter takings held', 'liability', null, 2),
        $account('2300', 'Packages sold not used', 'liability', null, 3),
        $account('4200', 'Chair rent from renters', 'income', 6, 4),
        $movement('01K9SACM000000000000000001', 'tipTaken', 3.5, 5),
        $movement('01K9SACM000000000000000002', 'tipPayout', -20, 6),
        $consent('01K9SACN000000000000000001', 'onlineBooking', 7),
        $consent('01K9SACN000000000000000002', 'import', 8),
        ...$journals,
    ]);

    expect(DB::table('ledger_accounts')->orderBy('code')->get(['code', 'type', 'vat_box'])->map(fn ($a) => [$a->code, $a->type, $a->vat_box])->all())
        ->toBe([['2280', 'liability', null], ['2290', 'liability', null], ['2300', 'liability', null], ['4200', 'income', 6]])
        ->and(CashMovement::withoutCompanyScope()->orderBy('id')->get()->map(fn (CashMovement $m) => [$m->type, $m->amount])->all())
        ->toBe([[CashMovementType::TipTaken, '3.50'], [CashMovementType::TipPayout, '-20.00']])
        ->and(Consent::withoutCompanyScope()->orderBy('id')->pluck('source')->all())->toBe([ConsentSource::OnlineBooking, ConsentSource::Import])
        ->and(DB::table('journal_entries')->orderBy('id')->pluck('ref_type')->all())->toBe($types);
});

it('never stores a salon client\'s health answers, nor logs them', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = [$e->message, $e->context];
    });
    $medical = ['seq' => 1, 'entity' => 'SalonClientMedical', 'entityId' => '01K9SAMD000000000000000001', 'op' => 'I', 'version' => 1,
        'companyId' => TillFixtures::COMPANY, 'branchId' => TillFixtures::LEEDS, 'registerId' => '', 'at' => '2026-11-08T09:00:00Z', 'key' => 'SalonClientMedical:1',
        'payload' => ['id' => '01K9SAMD000000000000000001', 'companyId' => TillFixtures::COMPANY, 'branchId' => TillFixtures::LEEDS, 'customerId' => '01K9S0000000000000000CU001',
            'answersJson' => '[{"key":"pregnant","answer":"Yes"}]', 'allergyText' => 'Severe reaction to PPD hair dye', 'signedName' => 'Zoe Patel'],
    ];

    $this->sync->push([$medical])->assertOk()->assertJson(['acknowledgedSeq' => 1, 'accepted' => 1]);

    $everything = json_encode([DB::table('till_unknown_rows')->get(), DB::table('sync_conflicts')->get(), DB::table('sync_applied_changes')->get(), $logged]);
    expect(EntityRegistry::isLocal('SalonClientMedical'))->toBeTrue()
        ->and(EntityRegistry::has('SalonClientMedical'))->toBeFalse()
        ->and(Schema::hasTable('salon_client_medicals'))->toBeFalse()
        ->and(DB::table('till_unknown_rows')->count())->toBe(0)
        ->and($everything)->not->toContain('PPD')->not->toContain('pregnant')->not->toContain('Zoe');
});

it('keeps an append-only row as first stored: a later change is recorded as a conflict', function () {
    $tip = Rows::id('SalonTip');
    ($this->push)([Rows::envelope('SalonTip', $tip, 1, ['amount' => 5, 'method' => 'cash'])]);
    $this->sync->push([Rows::envelope('SalonTip', $tip, 2, ['amount' => 50, 'method' => 'cash', 'rowVersion' => 2])])->assertOk();

    expect(SalonTip::withoutCompanyScope()->findOrFail($tip)->amount)->toBe('5.00')
        ->and(DB::table('sync_conflicts')->where('entity', 'SalonTip')->value('kind'))->toBe('immutableChange');
});

it('stores the salon settings a till may share and drops the secrets and one PC\'s keys', function () {
    $this->sync->push([
        Rows::setting('vertical.salon', 'true', 1),
        Rows::setting('salon.staff_word', 'Barber', 2),
        Rows::setting('salon.online.booking_url', 'https://book.example.co.uk/kings-cuts', 3),
        Rows::setting('salon.integrations.meta_access_token', 'EAAG-secret-token', 4),
        Rows::setting('salon.integrations.sms_secret', 'twilio-secret', 5),
        Rows::setting('salon.online.manage_link_key', 'c2VjcmV0LWtleQ', 6),
        Rows::setting('salon.queue.board_on', 'true', 7),
        Rows::setting('salon.queue.checkin_monitor', 'Monitor 2', 8),
    ])->assertOk()->assertJson(['acknowledgedSeq' => 8, 'accepted' => 8]);

    $stored = DB::table('till_settings')->pluck('value', 'setting_key')->all();
    ksort($stored);
    expect($stored)->toBe(['salon.online.booking_url' => 'https://book.example.co.uk/kings-cuts', 'salon.staff_word' => 'Barber', 'vertical.salon' => 'true'])
        ->and((string) json_encode([DB::table('till_unknown_rows')->get(), DB::table('sync_conflicts')->get()]))->not->toContain('secret')->not->toContain('c2VjcmV0');
});

it('has a valid salon pack sample for every schema the pack carries (the five calls are W3\'s)', function (string $sample) {
    $schema = str_starts_with($sample, 'online-request.')
        ? 'schemas/entities/SalonOnlineBooking.schema.json'
        : 'salon/schemas/'.preg_replace('/\.(joined|refused)$/', '', basename($sample, '.json')).'.schema.json';
    $data = json_decode((string) file_get_contents(ContractSchema::salonDir("salon/samples/{$sample}")), false, 512, JSON_THROW_ON_ERROR);

    expect(ContractSchema::salonErrors($data, $schema))->toBe([]);
})->with(array_map('basename', glob(dirname(__DIR__, 3).'/'.ContractSchema::SALON.'/salon/samples/*.json') ?: []));
