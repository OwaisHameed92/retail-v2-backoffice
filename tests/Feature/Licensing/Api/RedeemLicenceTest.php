<?php

use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LocalLicenceKey;
use App\Domain\Licensing\Models\LocalLicenceKeyRefusal;
use App\Domain\Licensing\Signing\Sspos\SsposCodec;
use App\Domain\Sync\Actions\IssueSyncKey;
use App\Domain\Sync\Enums\SyncKeySource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Support\SsposDocs;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

/*
 * Module 2.8: `POST /api/v1/licence/redeem` (contract v1.4.1 §17.6, §17.16) — local key reports, a token or a portal
 * key typed at a linked till. The redeem samples are replayed here; ContractReplyGuard checks every reply.
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
    [$this->company, $this->licence] = $this->keyedTenant();

    $this->report = fn (array $body, array $headers = []) => $this->till('licence/redeem', $body, [...$this->tillHeaders((string) ($body['installId'] ?? self::INSTALL)), ...$headers]);
    $this->linked = function (): array {
        $this->activateTill()->assertOk();

        return ['Authorization' => 'Bearer '.app(IssueSyncKey::class)->handle($this->branchOf($this->company), SyncKeySource::Admin)];
    };
    $this->sameMembers = function (TestResponse $reply, string $sample): void {
        $expected = SsposDocs::sample($sample);
        expect(array_keys($reply->json()))->toEqualCanonicalizing(array_keys($expected), $sample);

        foreach (['licence', 'details'] as $block) {
            if (is_array($expected[$block] ?? null)) {
                expect(array_values(array_diff(array_keys($expected[$block]), array_keys((array) $reply->json($block)))))->toBe([], "{$sample}: {$block}");
            }
        }
    };
    $this->localToken = fn (array $overrides) => SsposCodec::sign(SsposCodec::TOKEN_PREFIX, [
        ...SsposDocs::payloadOf(SsposDocs::sample('redeem-request.local-report.json')['key']), ...$overrides,
    ], SsposDocs::key(SsposDocs::APPROVER_KID)['secret']);
});

test('redeem-request.local-report.json: first sighting is recorded (redeem-reply.local-report.json), a retry again, a second PC gets 409', function () {
    $request = SsposDocs::sample('redeem-request.local-report.json');

    $reply = ($this->report)($request)->assertOk();
    ($this->sameMembers)($reply, 'redeem-reply.local-report.json');
    expect($reply->json())->toMatchArray(['result' => 'recorded', 'status' => 'active', 'licenceToken' => null, 'messages' => []])
        ->and($reply->json('licence'))->toMatchArray(['licenceId' => $request['licenceId'], 'source' => 'local', 'installCode' => 'Q3EG-1RNC', 'maxRegisters' => 2, 'expiresAt' => '2027-09-28T23:59:59Z']);

    $record = LocalLicenceKey::query()->sole();
    expect($record->only(['licence_id', 'install_code', 'install_id', 'device_name', 'kid', 'reported_via', 'report_count', 'claimed_branch_id']))->toBe([
        'licence_id' => $request['licenceId'], 'install_code' => 'Q3EG-1RNC', 'install_id' => $request['installId'], 'device_name' => 'TILL-2',
        'kid' => SsposDocs::APPROVER_KID, 'reported_via' => 'report', 'report_count' => 1, 'claimed_branch_id' => '01K5M1GR8T000000000000B004',
    ])->and($record->token_sha256)->toBe($request['tokenSha256'])
        ->and($record->company_id)->toBeNull();                 // a shop we do not know

    // The same PC reports again (the next daily check): recorded again, nothing refused.
    ($this->report)($request)->assertOk()->assertJsonPath('result', 'recorded');
    expect($record->fresh()->report_count)->toBe(2);

    // The same key from a second PC: 409, with the first PC named; the first PC's record is unchanged.
    $second = [...$request, 'installId' => self::OTHER_INSTALL, 'installCode' => self::OTHER_CODE, 'deviceName' => 'BACK-OFFICE'];
    $refused = ($this->report)($second)->assertStatus(409)->assertJsonPath('code', 'key.used_on_another_install');
    ($this->sameMembers)($refused, 'error.key-used-on-another-install.409.json');

    expect($refused->json('details'))->toBe(['licenceId' => $request['licenceId'], 'installCode' => 'Q3EG-1RNC', 'firstSeenUtc' => '2026-10-05T09:00:00Z'])
        ->and($record->fresh()->only(['install_code', 'refused_count']))->toBe(['install_code' => 'Q3EG-1RNC', 'refused_count' => 1])
        ->and(LocalLicenceKeyRefusal::query()->sole()->only(['install_code', 'install_id', 'device_name']))->toBe(['install_code' => self::OTHER_CODE, 'install_id' => self::OTHER_INSTALL, 'device_name' => 'BACK-OFFICE']);
});

test('a report is recorded even when the key has expired, and names our business when the till\'s ids are mapped', function () {
    $this->activateTill()->assertOk();   // maps the till's own ids (TILL_COMPANY, TILL_BRANCH) to our business
    $this->travelTo(CarbonImmutable::parse('2027-10-01 09:00:00', 'UTC'));
    $request = SsposDocs::sample('redeem-request.local-report.json');

    ($this->report)($request)->assertOk()->assertJsonPath('status', 'expired')->assertJsonPath('result', 'recorded');

    expect(LocalLicenceKey::query()->sole()->only(['company_id', 'branch_id']))->toBe(['company_id' => $this->company->id, 'branch_id' => $this->licence->branch_id]);
});

test('a token that is not genuine is 422 with the till\'s own names, and nothing is recorded', function () {
    $request = SsposDocs::sample('redeem-request.local-report.json');
    [$prefix, $payload, $signature] = explode('.', $request['key']);
    $tampered = $prefix.'.'.$payload.'.'.substr($signature, 0, 10).($signature[10] === 'A' ? 'B' : 'A').substr($signature, 11);

    ($this->sameMembers)(($this->report)([...$request, 'key' => $tampered])->assertStatus(422)->assertJsonPath('code', 'licence.bad_signature'), 'error.licence-bad-signature.422.json');
    ($this->report)([...$request, 'key' => 'SSPOS1.not-a-token'])->assertStatus(422)->assertJsonPath('code', 'licence.format');
    ($this->report)([...$request, 'key' => ($this->localToken)(['v' => 2])])->assertStatus(422)->assertJsonPath('code', 'licence.unsupported_version');

    config(['licence.trusted_keys' => []]);
    ($this->report)([...$request, 'key' => ($this->localToken)(['licenceId' => '01K5M1GR8T000000000000Y005'])])
        ->assertStatus(422)->assertJsonPath('code', 'licence.unknown_key')->assertJsonPath('details.kid', SsposDocs::APPROVER_KID);

    expect(LocalLicenceKey::query()->count())->toBe(0);
});

test('redeem-request.local-token.json at a linked till is recorded for that shop (redeem-reply.recorded.json)', function () {
    $auth = ($this->linked)();
    $request = SsposDocs::sample('redeem-request.local-token.json');

    $reply = ($this->report)($request, $auth)->assertOk();
    ($this->sameMembers)($reply, 'redeem-reply.recorded.json');

    expect($reply->json())->toMatchArray(['result' => 'recorded', 'licenceToken' => null])
        ->and(LocalLicenceKey::query()->sole()->only(['company_id', 'branch_id', 'reported_via', 'install_code']))
        ->toBe(['company_id' => $this->company->id, 'branch_id' => $this->licence->branch_id, 'reported_via' => 'redeem', 'install_code' => '0M01-TFG6']);
});

test('a token at a linked till must be live and for this business and shop; a portal token is not taken', function () {
    $auth = ($this->linked)();
    $request = SsposDocs::sample('redeem-request.local-token.json');
    $key = fn (array $overrides) => [...$request, 'key' => ($this->localToken)(['installCode' => '0M01-TFG6', ...$overrides])];

    ($this->report)($key(['expiresAt' => '2026-10-01T00:00:00Z']), $auth)->assertStatus(422)->assertJsonPath('code', 'licence.expired');
    ($this->report)($key(['companyId' => '01K5M1GR8T000000000000C777', 'branchId' => '']), $auth)->assertStatus(422)->assertJsonPath('code', 'licence.wrong_shop');
    ($this->report)($key(['companyId' => self::TILL_COMPANY, 'branchId' => '01K5M1GR8T000000000000B777']), $auth)->assertStatus(422)->assertJsonPath('code', 'licence.wrong_branch');
    ($this->report)($key(['companyId' => self::TILL_COMPANY, 'branchId' => self::TILL_BRANCH, 'licenceId' => '01K5M1GR8T000000000000Y006']), $auth)->assertOk();

    $held = (string) $this->licence->fresh()?->token_sha256;
    $ours = (string) $this->till('licence/validate', [...$this->validateBody($this->licence->id, null), 'tokenSha256' => $held, 'approverKids' => ['k00000000']])->json('licenceToken');
    ($this->report)([...$request, 'key' => $ours], $auth)->assertStatus(403)->assertJsonPath('code', 'key.not_allowed');
});

test('redeem-request.portal-key.json: our key for this shop is applied to the PC (redeem-reply.applied.json); the sync key is not sent again', function () {
    $auth = ($this->linked)();
    $second = Licence::withoutCompanyScope()->where('branch_id', $this->licence->branch_id)->whereKeyNot($this->licence->id)->sole();
    $this->giveKey($second, self::OTHER_KEY);
    $request = [...SsposDocs::sample('redeem-request.portal-key.json'), 'key' => self::OTHER_KEY];

    $reply = ($this->report)($request, $auth)->assertOk();
    ($this->sameMembers)($reply, 'redeem-reply.applied.json');

    expect($reply->json('result'))->toBe('applied')
        ->and($reply->json())->not->toHaveKey('apiKey')
        ->and($this->verifyToken($reply)->licenceId())->toBe($second->id)
        ->and($this->verifyToken($reply)->get('installCode'))->toBe($request['installCode'])
        ->and($second->fresh()->device_id)->toBe($request['installId']);

    // The same key from another PC: already redeemed there.
    $other = [...$request, 'installId' => self::OTHER_INSTALL, 'installCode' => self::OTHER_CODE];
    ($this->sameMembers)(($this->report)($other, $auth)->assertStatus(409)->assertJsonPath('code', 'key.already_redeemed'), 'error.key-already-redeemed.409.json');
});

test('portal keys: unknown 404, another shop\'s 403, never without the branch key; tenant isolation', function () {
    $auth = ($this->linked)();
    $request = [...SsposDocs::sample('redeem-request.portal-key.json'), 'installId' => self::OTHER_INSTALL, 'installCode' => self::OTHER_CODE];

    ($this->report)([...$request, 'key' => 'SSP-AAAA-BBBB-CCCC-DDDD'], $auth)->assertNotFound()->assertJsonPath('code', 'key.not_found');
    ($this->report)([...$request, 'key' => self::KEY])->assertStatus(401)->assertJsonPath('code', 'auth.invalid_key');

    // Another business's key, typed at this business's till.
    $foreignKey = LicenceKey::generate()->formatted();
    [, $foreign] = $this->keyedTenant('Other Stores', 1, 'OTH', $foreignKey);
    ($this->report)([...$request, 'key' => $foreignKey], $auth)->assertForbidden()->assertJsonPath('code', 'key.not_for_this_branch');

    expect($foreign->fresh()->device_id)->toBeNull();
});

test('a retried report with the same Idempotency-Key gets the same reply; reports are rate limited per install', function () {
    config(['licence.api.rate_limits.redeem_per_hour' => 3]);
    $request = SsposDocs::sample('redeem-request.local-report.json');
    $headers = $this->tillHeaders($request['installId'], '01K5T0Q8C4000000000000K001');

    $first = $this->till('licence/redeem', $request, $headers)->assertOk();
    $this->till('licence/redeem', $request, $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($first->json());

    ($this->report)($request)->assertOk();
    ($this->report)($request)->assertStatus(429)->assertJsonPath('code', 'rate.limited');
    expect(LocalLicenceKey::query()->sole()->report_count)->toBe(2);
});
