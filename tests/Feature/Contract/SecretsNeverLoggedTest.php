<?php

use App\Domain\Licensing\LicenceKey;
use App\Domain\Sync\Actions\RevokeSyncKeys;
use App\Domain\TillData\Sync\SyncRowIds;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Feature\TillData\TillFixtures;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

/*
 * Module 2.6, contract v1.4.1 §21.8 "Secrets never leave the till": across activate, validate, deactivate, hello,
 * push and pull (successes, retries and errors) no log line holds a licence key, a sync key, a licence token or an
 * Authorization header, and no stored row holds a secret the till sent by mistake.
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
    config(['logging.default' => 'null', 'app.url' => 'https://portal.test', 'sync.hub_url' => null]);

    // The log spy: every log call (any level, any channel), with its context and any exception's trace.
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) {
        $context = array_map(fn ($v) => $v instanceof Throwable ? get_class($v).': '.$v->getMessage()."\n".$v->getTraceAsString() : $v, $e->context);
        $this->logged[] = strtoupper($e->level).' '.$e->message.' '.json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    });
});

/** @return list<string> the forms a secret may take in a log line */
function secretForms(string $secret): array
{
    $forms = [$secret, strtolower($secret), str_replace('-', '', $secret)];

    return array_values(array_unique(array_filter($forms, fn (string $form) => strlen($form) >= 12)));
}

test('§21.8: licence keys, sync keys, tokens and the Authorization header never reach a log line', function () {
    [, $licence] = $this->keyedTenant();
    $licence->forceFill(['features' => ['cloud_sync']])->save();
    config(['licence.api.rate_limits.activate_per_ip_per_hour' => 100]);
    $secrets = [self::KEY, self::OTHER_KEY, LicenceKey::parse(self::KEY)->body()];

    // Licence calls: activate (+ its replay), a wrong key, the key on a second PC, validate, deactivate.
    // This PC's own ids differ from the sync fixture's business below (one id_map per till id).
    $body = [...$this->activateBody(), 'existingIds' => ['companyId' => '01K5T0Q8C4000000000000C00F', 'branchId' => '01K5T0Q8C4000000000000B00F', 'registerId' => '01K5T0Q8C4000000000000R00F']];
    $headers = $this->tillHeaders();
    $activated = $this->till('licence/activate', $body, $headers)->assertOk();
    $this->till('licence/activate', $body, $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    $token = (string) $activated->json('licenceToken');
    $secrets = [...$secrets, $token, (string) $activated->json('apiKey')];
    $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE)->assertNotFound();
    $this->activateTill(install: self::OTHER_INSTALL, code: self::OTHER_CODE)->assertStatus(409);
    $this->till('licence/activate', ['licenceKey' => self::KEY])->assertStatus(400);
    $this->validateTill($licence->id, $token)->assertOk();
    $this->validateTill($licence->id, 'SSPOS1.not.ours')->assertNotFound();
    $this->postJson('/api/v1/licence/validate?licenceKey='.self::KEY, [], $this->tillHeaders())->assertStatus(400);
    $this->deactivateTill('01K5T0Q8C4000000000000R00F', apiKey: (string) $activated->json('apiKey'))->assertOk()->assertJsonPath('apiKeyRevoked', true);
    $this->validateTill($licence->id, $token)->assertOk()->assertJsonPath('status', 'released');

    // Sync calls of another business: hello, pushes (a rejected row, a deny-listed setting, a secret an older
    // till still sends), pulls, a wrong key and a revoked one.
    $sync = new SyncApiFixtures($this);
    $secrets = [...$secrets, $sync->leedsKey, $sync->bradfordKey, 'SECRET-HUB-KEY-0001', 'JBSWY3DPEHPK3PXP-0001'];
    $sync->hello()->assertOk();
    $sync->push(TillFixtures::sample('push-request.json'))->assertOk();
    $bad = TillFixtures::sample('push-request.second-till.json');
    $bad[array_search('Sale', array_column($bad, 'entity'), true)]['payload']['total'] = 'not money';
    $sync->push($bad)->assertOk();
    $setting = ['scope' => 'branch', 'scopeId' => '', 'key' => 'sync.hub_api_key', 'value' => 'SECRET-HUB-KEY-0001', 'updatedAt' => '2026-10-05T09:00:00Z'];
    $customer = [...TillFixtures::sample('entities/Customer.json'), 'id' => '01K5T0Q8C4000000000000A009'];
    $sync->push([
        ['seq' => 19000, 'entity' => 'Setting', 'entityId' => SyncRowIds::setting('branch', TillFixtures::LEEDS, 'sync.hub_api_key'), 'op' => 'U', 'version' => 19000,
            'companyId' => TillFixtures::COMPANY, 'branchId' => TillFixtures::LEEDS, 'registerId' => '', 'at' => '2026-10-05T09:00:00Z', 'payload' => $setting, 'key' => 'Setting:19000'],
        TillFixtures::envelope('FutureThing', ['id' => '01K5T0Q8C4000000000000F001', 'companyId' => TillFixtures::COMPANY, 'apiSecret' => 'JBSWY3DPEHPK3PXP-0001', 'password' => 'JBSWY3DPEHPK3PXP-0001'], 19001),
        TillFixtures::envelope('Customer', [...$customer, 'remoteApprovalSecret' => 'JBSWY3DPEHPK3PXP-0001'], 19002),
    ])->assertOk();
    $sync->pull(0, null, ['Accept-Encoding' => 'gzip'], bradford: true)->assertOk();
    $sync->hello(['Authorization' => 'Bearer SSK-AAAA-BBBB-CCCC-DDDD-EEEE-FFFF-GGGG-HHHH'])->assertStatus(401);
    app(RevokeSyncKeys::class)->handle($sync->bradford);
    $sync->pull(0, bradford: true)->assertStatus(401);

    $log = implode("\n", $this->logged);
    expect($this->logged)->not->toBeEmpty();

    foreach (array_filter($secrets) as $secret) {
        foreach (secretForms($secret) as $form) {
            expect(str_contains($log, $form))->toBeFalse('a log line holds a secret ('.substr($form, 0, 6).'…)');
        }
    }

    expect($log)->not->toContain('Bearer ')->not->toContain('SSK-AAAA');

    // Nothing stored holds the secrets the till sent by mistake (§21.8 test: search every stored payload).
    $stored = '';
    foreach (['till_settings', 'till_unknown_rows', 'customers', 'sync_conflicts', 'sync_applied_changes', 'sync_branch_status', 'licence_devices', 'licence_alerts', 'audit_logs'] as $table) {
        if (Schema::hasTable($table)) {
            $stored .= $table.': '.json_encode(DB::table($table)->get())."\n";
        }
    }

    foreach (array_filter($secrets) as $secret) {
        $where = implode(', ', array_map(fn ($line) => strstr($line, ':', true), array_filter(explode("\n", $stored), fn ($line) => str_contains($line, $secret))));
        expect($where)->toBe('', 'a stored row holds a secret ('.substr($secret, 0, 6).'…)');
    }
});
