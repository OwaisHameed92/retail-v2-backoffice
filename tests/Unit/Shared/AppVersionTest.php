<?php

use App\Domain\Shared\Support\AppVersion;

it('compares till versions as semver', function (string $a, string $b, int $expected) {
    expect(AppVersion::compare($a, $b))->toBe($expected);
})->with([
    ['0.1.10', '0.1.9', 1],
    ['0.1.2', '0.1.2+45', 0],
    ['0.1.2-beta.2', '0.1.2', -1],
    ['0.1.2-beta.10', '0.1.2-beta.2', 1],
    ['0.2', '0.1.99', 1],
    ['v1.0.0', '1.0.0', 0],
    ['nonsense', '0.0.1', -1],
]);

it('matches exact versions and wildcard series', function () {
    expect(AppVersion::matchesAny('0.1.4+7', ['0.1.4']))->toBeTrue()
        ->and(AppVersion::matchesAny('0.1.5', ['0.1.4']))->toBeFalse()
        ->and(AppVersion::matchesAny('0.1.5', ['0.1.*']))->toBeTrue()
        ->and(AppVersion::matchesAny('0.10.1', ['0.1.*']))->toBeFalse()
        ->and(AppVersion::matchesAny('0.1.5', []))->toBeFalse()
        ->and(AppVersion::isValid('0.1.0'))->toBeTrue()
        ->and(AppVersion::isValid('soon'))->toBeFalse();
});
