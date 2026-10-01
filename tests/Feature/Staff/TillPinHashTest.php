<?php

use App\Domain\Staff\Actions\SaveStaffMember;
use App\Domain\Staff\Actions\SetStaffPin;
use App\Domain\Staff\Queries\StaffScreens;
use App\Domain\Staff\Support\TillPinHasher;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Staff\StaffFixtures as Staff;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;

/** ANSWERS-2026-10-01 §1, contract §10.7: the till's own `pbkdf2$…` PIN hash, sent only to set or change a PIN. */
const SAMPLE_SALT = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F";
const SAMPLE_1234 = 'pbkdf2$100000$AAECAwQFBgcICQoLDA0ODw==$hp5sg1DFvrCsw5n7qsO2DSIEM4lrJqZHc00NjxWG4fo=';
const SAMPLE_0000 = 'pbkdf2$100000$AAECAwQFBgcICQoLDA0ODw==$aS55LrsMVg+Dlele5It7ctBUzXJV2B2/FMilt+7ObRM=';

test('it reproduces the till\'s two samples exactly (salt bytes 00..0F)', function () {
    expect(TillPinHasher::hashWithSalt('1234', SAMPLE_SALT))->toBe(SAMPLE_1234)
        ->and(TillPinHasher::hashWithSalt('0000', SAMPLE_SALT))->toBe(SAMPLE_0000)
        ->and((new TillPinHasher)->verify('1234', SAMPLE_1234))->toBeTrue()
        ->and((new TillPinHasher)->verify('0000', SAMPLE_0000))->toBeTrue()
        ->and((new TillPinHasher)->verify('1235', SAMPLE_1234))->toBeFalse();
});

test('a new hash is the till\'s format with a fresh 16-byte salt, 32-byte subkey and 100000 iterations', function () {
    $hasher = new TillPinHasher;
    $a = $hasher->hash('4821');
    $b = $hasher->hash('4821');
    [$tag, $iterations, $salt, $key] = explode('$', $a);

    expect($tag)->toBe('pbkdf2')->and($iterations)->toBe('100000')
        ->and(strlen((string) base64_decode($salt, true)))->toBe(16)
        ->and(strlen((string) base64_decode($key, true)))->toBe(32)
        ->and(strlen($a))->toBeLessThanOrEqual(200)
        ->and($a)->not->toBe($b)
        ->and($hasher->verify('4821', $a))->toBeTrue()
        ->and(TillPinHasher::isTillFormat($a))->toBeTrue();
});

test('an ASP.NET Identity v3 hash is not the till\'s format: it needs resetting and never verifies', function () {
    $salt = random_bytes(16);
    $identity = base64_encode(chr(1).pack('NNN', 1, 10000, 16).$salt.hash_pbkdf2('sha256', '4821', $salt, 10000, 32, true));

    expect(TillPinHasher::isTillFormat($identity))->toBeFalse()
        ->and(TillPinHasher::needsReset($identity))->toBeTrue()
        ->and(TillPinHasher::needsReset(''))->toBeFalse()
        ->and(TillPinHasher::needsReset(null))->toBeFalse()
        ->and((new TillPinHasher)->verify('4821', $identity))->toBeFalse()
        ->and(TillPinHasher::isTillFormat('PBKDF2$100000$AAECAwQFBgcICQoLDA0ODw==$hp5sg1DFvrCsw5n7qsO2DSIEM4lrJqZHc00NjxWG4fo='))->toBeFalse()
        ->and(TillPinHasher::isTillFormat(str_replace('+', '-', SAMPLE_0000)))->toBeFalse();
});

describe('pull', function () {
    beforeEach(function () {
        $this->sync = new SyncApiFixtures($this);
        $this->company = $this->sync->company;
        $this->travelTo('2026-10-22 09:00:00');
        Staff::roles($this->company);
        $this->user = fn ($reply, string $id) => collect(Pull::changes($reply))->firstWhere('entityId', $id);
    });

    test('pinHash goes to a till only when the PIN was set or changed after its cursor', function () {
        $member = Staff::member($this->company, 'Aisha Patel', '4821');
        $first = $this->sync->pull(0)->assertOk();
        $hash = ($this->user)($first, $member->id)['payload']['pinHash'];
        expect((new TillPinHasher)->verify('4821', $hash))->toBeTrue()->and($hash)->toStartWith('pbkdf2$100000$');

        // A name edit after the till has the PIN: the row goes, the PIN does not.
        $this->travel(1)->minutes();
        app(SaveStaffMember::class)->handle($this->company, $member->id, ['name' => 'Aisha Shah', 'role_id' => Staff::CASHIER]);
        $since = (int) $first->json('highestVersion');
        $edit = ($this->user)($this->sync->pull($since), $member->id);
        expect($edit['payload'])->toMatchArray(['name' => 'Aisha Shah'])->not->toHaveKey('pinHash')
            // A till that never had it (cursor 0) still gets it.
            ->and(($this->user)($this->sync->pull(0), $member->id)['payload']['pinHash'])->toBe($hash);

        app(SetStaffPin::class)->handle($this->company, $member->id, '5930');
        $changed = ($this->user)($this->sync->pull($since), $member->id)['payload']['pinHash'];
        expect((new TillPinHasher)->verify('5930', $changed))->toBeTrue();
    });

    test('a stored Identity v3 hash is "PIN needs resetting" and never sent', function () {
        $member = Staff::member($this->company, 'Aisha Patel', '4821');
        $identity = base64_encode(chr(1).pack('NNN', 1, 10000, 16).str_repeat("\x01", 16).str_repeat("\x02", 32));
        DB::table('till_users')->where('id', $member->id)->update(['pin_hash' => $identity, 'pin_hash_version' => null, 'pin_hash_versioned' => null, 'hub_version' => null]);

        $reply = $this->sync->pull(0)->assertOk();
        expect(($this->user)($reply, $member->id)['payload'])->not->toHaveKey('pinHash')
            ->and((string) $reply->getContent())->not->toContain('AQAAAA');

        $row = app(CurrentCompany::class)->runAs($this->company, fn () => StaffScreens::form($member->fresh())['member']);
        expect($row)->toMatchArray(['hasPin' => false, 'pinNeedsReset' => true]);

        app(SetStaffPin::class)->handle($this->company, $member->id, '5930');
        $row = app(CurrentCompany::class)->runAs($this->company, fn () => StaffScreens::form($member->fresh())['member']);
        expect($row)->toMatchArray(['hasPin' => true, 'pinNeedsReset' => false])
            ->and((new TillPinHasher)->verify('5930', ($this->user)($this->sync->pull(0), $member->id)['payload']['pinHash']))->toBeTrue();
    });
});
