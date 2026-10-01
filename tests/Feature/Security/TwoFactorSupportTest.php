<?php

use App\Domain\Audit\Data\AuditFilters;
use App\Domain\Audit\Support\AuditLabels;
use App\Domain\Audit\Support\AuditPresenter;
use App\Domain\Security\Support\RecoveryCodes;
use App\Domain\Security\Support\Totp;
use Illuminate\Http\Request;

/*
 * The small building blocks of two-factor sign-in and the audit screens.
 */

test('recovery codes are ten distinct readable codes, hashed with the app key', function () {
    $codes = RecoveryCodes::generate();

    expect($codes)->toHaveCount(10)
        ->and(array_unique($codes))->toHaveCount(10)
        ->and($codes)->each->toMatch('/^[a-hjkmnp-z2-9]{5}-[a-hjkmnp-z2-9]{5}$/')
        ->and(RecoveryCodes::hash($codes[0]))->toBe(RecoveryCodes::hash(' '.strtoupper(str_replace('-', '', $codes[0])).' '))
        ->and(RecoveryCodes::hash($codes[0]))->not->toBe(RecoveryCodes::hash($codes[1]))
        ->and(RecoveryCodes::looksLikeCode($codes[0]))->toBeTrue()
        ->and(RecoveryCodes::looksLikeCode('123456'))->toBeFalse();
});

test('a TOTP code is accepted once per time step and only near now', function () {
    $totp = new Totp;
    $secret = $totp->newSecret();
    $step = $totp->verify($secret, $totp->current($secret));

    expect($step)->toBeInt()
        ->and($totp->verify($secret, $totp->current($secret), $step))->toBeNull()
        ->and($totp->verify($secret, 'abc123'))->toBeNull()
        ->and($totp->verify($secret, '12345'))->toBeNull()
        ->and($totp->otpauthUrl('Switch & Save', 'a@b.test', $secret))->toStartWith('otpauth://totp/')
        ->and($totp->qrSvg('otpauth://totp/x?secret='.$secret))->toStartWith('<svg')
        ->and(Totp::formatSecret('ABCDEFGH'))->toBe('ABCD EFGH');
});

test('the audit diff lists changed, added and removed fields only', function () {
    expect(AuditPresenter::changes(['a' => 1, 'b' => true, 'c' => 'x'], ['a' => 1, 'b' => false, 'd' => ['k' => 'v'], 'e' => null]))->toBe([
        ['field' => 'b', 'label' => 'B', 'before' => 'Yes', 'after' => 'No'],
        ['field' => 'c', 'label' => 'C', 'before' => 'x', 'after' => null],
        ['field' => 'd', 'label' => 'D', 'before' => null, 'after' => '{"k":"v"}'],
    ])->and(AuditPresenter::changes(null, null))->toBe([]);
});

test('audit labels read like sentences', function () {
    expect(AuditLabels::action('two_factor.recovery_code_used'))->toBe('Two-factor recovery code used')
        ->and(AuditLabels::type('App\\Domain\\Licensing\\Models\\LicenceKey'))->toBe('Licence key')
        ->and(AuditLabels::field('maxBranches'))->toBe('Max branches');
});

test('malformed audit filters are ignored and days are UK days', function () {
    $f = AuditFilters::fromRequest(Request::create('/x', 'GET', [
        'actor' => 'root;drop', 'company' => 'nope', 'action' => 'licence.*', 'from' => '2026-02-30', 'to' => '2026-07-01', 'search' => str_repeat('x', 101),
    ]), true);

    expect($f->actor)->toBeNull()->and($f->company)->toBeNull()->and($f->action)->toBe('licence.*')
        ->and($f->from)->toBeNull()->and($f->search)->toBeNull()
        ->and($f->toUtcExclusive()?->toIso8601ZuluString())->toBe('2026-07-01T23:00:00Z');

    expect(AuditFilters::fromRequest(Request::create('/x', 'GET', ['company' => '01K5VB0000000000000000ABCD']), false)->company)->toBeNull();
});
