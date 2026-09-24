<?php

use App\Domain\Licensing\Exceptions\InvalidLicenceKey;
use App\Domain\Licensing\LicenceKey;

/** Test vectors published in docs/specs/licence-api-v1.md ("Key format"). */
dataset('licence key vectors', [
    ['7K2Q9DMF3XRAP8T', '5', 'SSP-7K2Q-9DMF-3XRA-P8T5'],
    ['4HWCJ6ZB81MEQV5', 'H', 'SSP-4HWC-J6ZB-81ME-QV5H'],
    ['2NRXT7KP5G0ADYF', '6', 'SSP-2NRX-T7KP-5G0A-DYF6'],
    ['123456789ABCDEF', '8', 'SSP-1234-5678-9ABC-DEF8'],
    ['0123456789ABCDE', 'Z', 'SSP-0123-4567-89AB-CDEZ'],
    ['000000000000000', '0', 'SSP-0000-0000-0000-0000'],
]);

it('computes the documented check characters', function (string $payload, string $check, string $formatted) {
    expect(LicenceKey::checkCharacter($payload))->toBe($check)
        ->and(LicenceKey::parse($formatted)->formatted())->toBe($formatted)
        ->and(LicenceKey::hasValidCheckCharacter($payload.$check))->toBeTrue();
})->with('licence key vectors');

it('generates keys in the SSP-XXXX-XXXX-XXXX-XXXX format with a valid check character', function () {
    for ($i = 0; $i < 200; $i++) {
        $key = LicenceKey::generate();

        expect($key->formatted())->toMatch('/^SSP-[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/')
            ->and(LicenceKey::isValid($key->formatted()))->toBeTrue()
            ->and($key->last4())->toBe(substr($key->body(), -4));
    }
});

it('generates different keys every time', function () {
    $keys = array_map(fn () => LicenceKey::generate()->body(), range(1, 500));

    expect(array_unique($keys))->toHaveCount(500);
});

it('never uses I, L, O or U', function () {
    $all = implode('', array_map(fn () => LicenceKey::generate()->body(), range(1, 300)));

    expect($all)->not->toMatch('/[ILOU]/');
});

it('normalises what people type', function (string $typed) {
    expect(LicenceKey::parse($typed)->formatted())->toBe('SSP-7K2Q-9DMF-3XRA-P8T5');
})->with([
    'exact' => 'SSP-7K2Q-9DMF-3XRA-P8T5',
    'lower case' => 'ssp-7k2q-9dmf-3xra-p8t5',
    'spaces' => ' SSP 7K2Q 9DMF 3XRA P8T5 ',
    'no dashes' => 'SSP7K2Q9DMF3XRAP8T5',
    'no prefix' => '7K2Q-9DMF-3XRA-P8T5',
    'no prefix, no dashes' => '7k2q9dmf3xrap8t5',
    'underscores and dots' => 'SSP_7K2Q.9DMF_3XRA.P8T5',
]);

it('maps O to 0 and I or L to 1', function () {
    expect(LicenceKey::normalise('SSP-O123-4567-89AB-CDEZ'))->toBe('0123456789ABCDEZ')
        ->and(LicenceKey::normalise('ssp-1l1i'))->toBe('SSP1111')
        ->and(LicenceKey::parse('SSP-OI23-4567-89AB-CDEZ')->formatted())->toBe(LicenceKey::parse('SSP-0123-4567-89AB-CDEZ')->formatted());
});

it('rejects keys of the wrong shape', function (string $typed) {
    LicenceKey::parse($typed);
})->with([
    'too short' => 'SSP-7K2Q-9DMF-3XRA-P8T',
    'too long' => 'SSP-7K2Q-9DMF-3XRA-P8T55',
    'U is not base32' => 'SSP-7K2Q-9DMF-3XRA-P8TU',
    'symbols' => 'SSP-7K2Q-9DMF-3XRA-P8T$',
    'empty' => '',
    'another prefix' => 'ABC-7K2Q-9DMF-3XRA-P8T5',
])->throws(InvalidLicenceKey::class);

it('catches every single-character typo', function () {
    $body = LicenceKey::parse('SSP-7K2Q-9DMF-3XRA-P8T5')->body();

    for ($i = 0; $i < LicenceKey::LENGTH; $i++) {
        foreach (str_split(LicenceKey::ALPHABET) as $char) {
            if ($char === $body[$i]) {
                continue;
            }
            $typo = $body;
            $typo[$i] = $char;

            expect(LicenceKey::hasValidCheckCharacter($typo))->toBeFalse("typo {$char} at {$i} not caught");
        }
    }
});

it('catches swapped neighbours except 0 and Z', function () {
    expect(LicenceKey::isValid('SSP-7K2Q-9DMF-3XRA-8PT5'))->toBeFalse()
        ->and(LicenceKey::isValid('SSP-K72Q-9DMF-3XRA-P8T5'))->toBeFalse();
});

it('says why a key is invalid without repeating it', function () {
    try {
        LicenceKey::parse('SSP-7K2Q-9DMF-3XRA-P8T6');
    } catch (InvalidLicenceKey $e) {
        expect($e->getMessage())->toContain('typo')->not->toContain('P8T6');

        return;
    }

    $this->fail('No exception');
});

it('masks keys to their last 4 characters', function () {
    expect(LicenceKey::mask('B6WN'))->toBe('SSP-••••-••••-••••-B6WN');
});

it('hashes with HMAC-SHA256 over the normalised body', function () {
    $body = '7K2Q9DMF3XRAP8T5';

    expect(LicenceKey::hashWith($body, 'secret-a'))->toBe(hash_hmac('sha256', $body, 'secret-a'))
        ->toHaveLength(64)
        ->not->toBe(LicenceKey::hashWith($body, 'secret-b'));
});

it('never prints or serialises the key', function () {
    $key = LicenceKey::parse('SSP-7K2Q-9DMF-3XRA-P8T5');

    expect(print_r($key, true))->not->toContain('9DMF')->toContain('P8T5')
        ->and(fn () => serialize($key))->toThrow(LogicException::class);
});
