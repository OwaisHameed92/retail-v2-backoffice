<?php

use App\Domain\Licensing\Actions\RenewLicence;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Licensing\Enums\LicenceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Support\SsposDocs;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

/*
 * Module 2.6: the licensing samples (contract v1.4.1 §17.5, §17.7, §17.12, §17.15) replayed through our endpoints,
 * our replies compared with the sample replies member by member. Every reply is also schema-checked by
 * ContractReplyGuard after each test.
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
    [$this->company, $this->licence] = $this->keyedTenant();

    $this->sameError = function (TestResponse $reply, string $file): void {
        preg_match('/\.(\d{3})\.json$/', $file, $status);

        expect($reply->status())->toBe((int) $status[1], $file)
            ->and($reply->json('code'))->toBe(SsposDocs::sample($file)['code'], $file);
        ($this->sameMembers)($reply, $file, []);
    };

    /** Our reply carries every member of the sample reply (the branch model's `registers` aside, §17.15). */
    $this->sameMembers = function (TestResponse $reply, string $sample, array $exceptSample = ['registers']): void {
        $expected = SsposDocs::sample($sample);
        $keys = fn (array $a) => array_values(array_diff(array_keys($a), $exceptSample));

        expect(array_values(array_diff($keys($expected), array_keys($reply->json()))))->toBe([], "{$sample}: members we do not send")
            ->and(array_values(array_diff(array_keys($reply->json()), array_keys($expected))))->toBe([], "{$sample}: members the sample does not have");

        foreach (['licence', 'details'] as $block) {
            if (is_array($expected[$block] ?? null) && $expected[$block] !== []) {
                expect(array_values(array_diff(array_keys($expected[$block]), array_keys((array) $reply->json($block)))))->toBe([], "{$sample}: {$block} members we do not send");
            }
        }
    };
});

test('licence-activate-reply.json: activating with the request sample answers the sample\'s members and a verifiable token', function () {
    $request = [...SsposDocs::sample('licence-activate-request.json'), 'licenceKey' => self::KEY];
    $reply = $this->till('licence/activate', $request, $this->tillHeaders($request['installId']))->assertOk();

    ($this->sameMembers)($reply, 'licence-activate-reply.json');
    expect($reply->json('status'))->toBeIn(['active', 'expiring'])
        ->and($reply->json('licence.installCode'))->toBe($request['installCode'])
        ->and($this->verifyToken($reply)->licenceId())->toBe($this->licence->id);
});

test('the validate-reply samples (per-till, renewed, released, revoked, trial-expiring) match our replies for the same states', function () {
    $token = (string) $this->activateTill()->assertOk()->json('licenceToken');
    $validate = fn (string $held) => $this->validateTill($this->licence->id, $held)->assertOk();

    // A 7-day trial is `expiring` from day one; nothing changed, so no new token.
    $reply = $validate($token);
    ($this->sameMembers)($reply, 'validate-reply.trial-expiring.json');
    expect($reply->json('status'))->toBe('expiring')->and($reply->json('licenceToken'))->toBeNull();

    // Paid for a year: a new full token (renewed), then the same token again (per-till: active, no token).
    app(RenewLicence::class)->handle($this->licence->fresh(), RenewalTerm::year());
    $reply = $validate($token);
    ($this->sameMembers)($reply, 'validate-reply.renewed.json');
    expect($reply->json('status'))->toBe('active')->and($reply->json('licenceToken'))->toStartWith('SSPOS1.');
    $token = (string) $reply->json('licenceToken');

    $reply = $validate($token);
    ($this->sameMembers)($reply, 'validate-reply.per-till.json');
    expect($reply->json('status'))->toBe(SsposDocs::sample('validate-reply.per-till.json')['status'])->and($reply->json('licenceToken'))->toBeNull();

    app(RevokeLicence::class)->handle($this->licence->fresh(), 'Contract replay');
    $reply = $validate($token);
    ($this->sameMembers)($reply, 'validate-reply.revoked.json');
    expect($reply->json('status'))->toBe('revoked')->and($reply->json('licenceToken'))->toBeNull();
});

test('validate-reply.released.json: a till released from its key is told `released`', function () {
    $token = (string) $this->activateTill()->assertOk()->json('licenceToken');
    $this->deactivateTill()->assertOk();

    $reply = $this->validateTill($this->licence->id, $token)->assertOk();
    ($this->sameMembers)($reply, 'validate-reply.released.json');
    expect($reply->json('status'))->toBe('released')->and($reply->json('licenceToken'))->toBeNull();
});

test('validate-request.json: the branch model\'s body (registers, diagnostics, lastSyncAt; install id in the header) is read too', function () {
    $this->activateTill()->assertOk();
    $body = [...SsposDocs::sample('validate-request.json'), 'licenceId' => $this->licence->id];

    $reply = $this->till('licence/validate', $body, $this->tillHeaders(self::INSTALL))->assertOk();

    expect($reply->json('status'))->toBe('expiring')
        ->and($reply->json('licence.licenceId'))->toBe($this->licence->id)
        // The sample's tokenSha256 is another token's: ours is sent again.
        ->and($reply->json('licenceToken'))->toStartWith('SSPOS1.');
});

test('deactivate-request.json: a secondary till gives its key back and gets deactivate-reply.json', function () {
    $request = SsposDocs::sample('deactivate-request.json');
    $activate = [...$this->activateBody(install: $request['installId']), 'existingIds' => ['companyId' => self::TILL_COMPANY, 'branchId' => self::TILL_BRANCH, 'registerId' => $request['registerId']]];
    $this->till('licence/activate', $activate, $this->tillHeaders($request['installId']))->assertOk();

    $reply = $this->till('devices/deactivate', $request, $this->tillHeaders($request['installId']))->assertOk();
    $sample = SsposDocs::sample('deactivate-reply.json');

    ($this->sameMembers)($reply, 'deactivate-reply.json');
    expect($reply->json())->toMatchArray([
        'registerId' => $request['registerId'], 'seat' => $sample['seat'], 'seatsInUse' => 0, 'apiKeyRevoked' => false,
        'transferCode' => null, 'transferCodeExpiresAt' => null, 'messages' => [],
    ])->and($this->licence->fresh()->device_id)->toBeNull();
});

test('deactivate-request.main-till.json: the main till that holds the branch sync key gets apiKeyRevoked true', function () {
    $this->licence->forceFill(['features' => ['cloud_sync']])->save();
    $request = SsposDocs::sample('deactivate-request.main-till.json');
    $activate = [...$this->activateBody(install: $request['installId']), 'existingIds' => ['companyId' => self::TILL_COMPANY, 'branchId' => self::TILL_BRANCH, 'registerId' => $request['registerId']]];
    expect($this->till('licence/activate', $activate, $this->tillHeaders($request['installId']))->assertOk()->json('apiKey'))->toStartWith('SSK-');

    $reply = $this->till('devices/deactivate', $request, $this->tillHeaders($request['installId']))->assertOk();

    ($this->sameMembers)($reply, 'deactivate-reply.main-till.json');
    // Per-till keys move by activating the same key on the new PC (§17.15): no transfer code (that is cloud/migrate).
    expect($reply->json())->toMatchArray(['registerId' => $request['registerId'], 'seat' => 'deactivated', 'apiKeyRevoked' => true, 'transferCode' => null]);
});

test('deactivate-reply.main-till.same-key.json: the main till is released with no transfer code, the next step in messages[], and the same key activates on a new PC', function () {
    $this->licence->forceFill(['features' => ['cloud_sync']])->save();
    $request = SsposDocs::sample('deactivate-request.main-till.json');
    $activate = [...$this->activateBody(install: $request['installId']), 'existingIds' => ['companyId' => self::TILL_COMPANY, 'branchId' => self::TILL_BRANCH, 'registerId' => $request['registerId']]];
    $this->till('licence/activate', $activate, $this->tillHeaders($request['installId']))->assertOk();

    $reply = $this->till('devices/deactivate', $request, $this->tillHeaders($request['installId']))->assertOk();
    $sample = SsposDocs::sample('deactivate-reply.main-till.same-key.json');

    ($this->sameMembers)($reply, 'deactivate-reply.main-till.same-key.json');
    expect($reply->json())->toMatchArray(['seat' => $sample['seat'], 'apiKeyRevoked' => true, 'transferCode' => null, 'transferCodeExpiresAt' => null])
        ->and($reply->json('messages'))->toHaveCount(1)
        ->and(array_keys($reply->json('messages.0')))->toEqualCanonicalizing(array_keys($sample['messages'][0]))
        ->and($reply->json('messages.0'))->toMatchArray(['level' => 'info', 'title' => $sample['messages'][0]['title'], 'dismissible' => false])
        ->and($reply->json('messages.0.text'))->toStartWith('Activate this same licence key on the new PC');

    // The key's binding to the old install is released: the new PC's licence/activate gets 200, not key.already_used.
    $this->activateTill(install: self::OTHER_INSTALL, code: self::OTHER_CODE)->assertOk();
    expect($this->licence->fresh()->device_id)->toBe(self::OTHER_INSTALL);
});

test('the error samples we emit: same HTTP status, code and details members', function () {
    config(['licence.api.rate_limits.activate_per_ip_per_hour' => 100]);
    $branch = $this->branchOf($this->company);
    $replies = [];

    // Till 02 is already in use and the shop is allowed one till: this PC is over the limit.
    $this->licenceOf($this->registerOf($branch, '02'))->forceFill(['device_id' => self::OTHER_INSTALL, 'bound_at' => now(), 'activated_at' => now(), 'status' => LicenceStatus::Trial, 'trial_ends_at' => now()->addDays(7)])->save();
    $this->allowTills($branch, 1);
    $replies['error.seat-limit.403.json'] = $this->activateTill();
    $this->allowTills($branch, 2);

    $this->activateTill()->assertOk();
    $replies['error.key-already-used.409.json'] = $this->activateTill(install: self::OTHER_INSTALL, code: self::OTHER_CODE);
    $replies['error.key-not-found.404.json'] = $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE);

    foreach (range(1, 4) as $i) {
        $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE);
    }
    $replies['error.activation-too-many-attempts.429.json'] = $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE);

    foreach ($replies as $file => $reply) {
        ($this->sameError)($reply, $file);
    }
});

test('the error samples we emit on sync/*: 426 app.update_required only for an app version listed as damaging data', function () {
    config(['sync.blocked_app_versions' => ['3.0.412']]);
    $sync = new SyncApiFixtures($this);

    ($this->sameError)($sync->hello(), 'error.update-required.426.json');
});
