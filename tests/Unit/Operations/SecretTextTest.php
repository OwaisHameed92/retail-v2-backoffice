<?php

use App\Domain\Operations\Support\CronText;
use App\Domain\Operations\Support\SecretText;

it('removes secrets from free text', function (string $text, string $expected) {
    expect(SecretText::clean($text))->toBe($expected);
})->with([
    'name=value' => ['Login failed: password=hunter22 for owner', 'Login failed: password=[redacted] for owner'],
    'name: value' => ['api_key: abcd1234 refused', 'api_key: [redacted] refused'],
    'json member' => ['{"syncApiKey":"sk-secret-1","name":"Khan"}', '{"syncApiKey":"[redacted]","name":"Khan"}'],
    'bearer' => ['sent Bearer eyJhbGciOiJIUzI1NiJ9.e30.abc to GoCardless', 'sent Bearer [redacted] to GoCardless'],
    'authorization header' => ['Authorization: Bearer live_abcdefghij', 'Authorization: [redacted]'],
    'url password' => ['mysql://root:s3cret@127.0.0.1/db', 'mysql://root:[redacted]@127.0.0.1/db'],
    'query key' => ['GET https://isd.digital.nhs.uk/trud/api/v1/keys/x/items?key=abc123&page=1', 'GET https://isd.digital.nhs.uk/trud/api/v1/keys/x/items?key=[redacted]&page=1'],
    'licence key' => ['Licence SSP-AB12-CD34-EF56-GH78 is bound elsewhere', 'Licence [licence key] is bound elsewhere'],
    'provider key' => ['used sk_live_51HxYzAbCdEfGhIjKlMn here', 'used [redacted] here'],
    'hex token' => ['reset link /reset-password/'.str_repeat('a1', 32).' expired', 'reset link /reset-password/[redacted] expired'],
    'random token' => ['invite Xq7pL2mN9vB4kR8tW3yZ6cF1hJ5gD0sA used', 'invite [redacted] used'],
    'serialised member' => ['s:8:"password";s:8:"hunter22";s:4:"name";s:4:"Khan";', 's:8:"password";s:10:"[redacted]";s:4:"name";s:4:"Khan";'],
]);

it('keeps ordinary text, ids, class names and file paths readable', function () {
    $text = 'Illuminate\\Database\\QueryException: SQLSTATE[23000] in /var/www/app/Domain/Billing/Actions/RunBilling.php(42) for 01K5VB0000000000000000C001';

    expect(SecretText::clean($text))->toBe($text);
});

it('gives the first non-empty line, cleaned and cut', function () {
    expect(SecretText::firstLine("\n\nRuntimeException: token=abc\n#0 trace"))->toBe('RuntimeException: token=[redacted]')
        ->and(SecretText::firstLine(str_repeat('x', 400), 10))->toBe('xxxxxxxxx…')
        ->and(SecretText::firstLine(null))->toBe('');
});

it('describes the cron expressions the schedule uses in plain words', function (string $expression, string $text) {
    expect(CronText::describe($expression))->toBe($text);
})->with([
    ['* * * * *', 'Every minute'],
    ['*/5 * * * *', 'Every 5 minutes'],
    ['2-59/5 * * * *', 'Every 5 minutes from :02'],
    ['0 * * * *', 'Hourly'],
    ['15 * * * *', 'Hourly at :15'],
    ['5 0 * * *', 'Daily at 00:05'],
    ['30 7 * * 1', 'Every Monday at 07:30'],
    ['0 0 1 * *', '0 0 1 * *'],
]);
