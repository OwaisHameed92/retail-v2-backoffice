<?php

use App\Domain\OnlineStore\Payments\DnaSignature;
use App\Domain\OnlineStore\Payments\LzString;

/*
 * OS-9: the two pieces of DNA's PHP SDK reimplemented here, checked against values the SDK itself produced: the
 * lz-string encoding of the hosted checkout's address, and the result signature (HMAC-SHA256 over id + amount +
 * currency + invoiceId + errorCode + json(success), keyed on the client secret).
 */

test('lz-string matches the DNA SDK', function (string $input, string $expected) {
    expect(LzString::compressToEncodedURIComponent($input))->toBe($expected);
})->with([
    'repeats' => ['hello world hello world', 'BYUwNmD2AEDukCcwBNqgjeTlA'],
    'the page settings' => ['{"isTestMode":true,"scopes":{"allowHosted":true},"autoRedirectDelayInMs":5000}', 'N4IglgzgKgphAuBZA9gExiAXPATgVxgBoQIBjZABzi1AEMAbe5AdwAlkEZUtcCBfYrTzxkAJS5gcMUvAAiMerQCeASQB2iCFgCsABn18gA'],
    'beyond ASCII' => ['Ünïcödé £ 漢字', 'DsOw9wxgbwJglwAgMUMET2h1bSA'],
    'empty' => ['', ''],
]);

test('the signature matches the DNA SDK and is checked in constant time; a missing field fails', function () {
    $result = ['id' => 'txn-1', 'amount' => 12.5, 'currency' => 'GBP', 'invoiceId' => 'W-0001-ABC', 'errorCode' => 0, 'success' => true];
    $signed = [...$result, 'signature' => 'rAhBftU8AO0M0YEki+O6Ti4V7xQDkfq1AbIMMseFDNw='];

    expect(DnaSignature::sign($result, 'sekret'))->toBe($signed['signature'])
        ->and(DnaSignature::verify($signed, 'sekret'))->toBeTrue()
        ->and(DnaSignature::verify($signed, 'other'))->toBeFalse()
        ->and(DnaSignature::verify([...$signed, 'success' => false], 'sekret'))->toBeFalse()
        ->and(DnaSignature::verify(array_diff_key($signed, ['currency' => true]), 'sekret'))->toBeFalse()
        ->and(DnaSignature::verify([...$signed, 'signature' => ['x']], 'sekret'))->toBeFalse();
});
