<?php

use App\Domain\Sync\Actions\IssueSyncKey;
use App\Domain\Sync\Actions\RevokeSyncKeys;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Enums\IdMapAction;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Models\IdMapping;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Tenancy\Actions\DeactivateBranch;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Http\Middleware\AuthenticateSyncKey;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Module 2.1: `sync/*` Bearer auth, ready for 2.2 (contract §3, §4.1, §9). */
const TILL_C = '01K5T0Q8C4000000000000C001';

const TILL_B = '01K5T0Q8C4000000000000B001';

const TILL_R = '01K5T0Q8C4000000000000R001';

beforeEach(function () {
    Route::middleware(['api', AuthenticateSyncKey::class])->get('/api/v1/sync/_probe', function (Request $request) {
        $caller = AuthenticateSyncKey::caller($request);

        return ['company' => $caller->company->id, 'branch' => $caller->branch->id, 'register' => $caller->registerId, 'tillBranch' => $caller->tillBranchId];
    });

    $this->company = Company::factory()->create();
    $this->branch = Branch::factory()->forCompany($this->company)->create(['code' => 'LDS']);
    $this->till = Register::factory()->forBranch($this->branch)->create(['code' => '01', 'is_main_till' => true]);
    foreach ([[IdKind::Company, TILL_C, $this->company->id], [IdKind::Branch, TILL_B, $this->branch->id], [IdKind::Register, TILL_R, $this->till->id]] as [$kind, $till, $ours]) {
        IdMapping::withoutCompanyScope()->create(['kind' => $kind, 'till_id' => $till, 'portal_id' => $ours, 'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'action' => IdMapAction::Adopted]);
    }
    $this->key = app(IssueSyncKey::class)->handle($this->branch, SyncKeySource::Admin);
});

function probe(object $test, ?string $key, array $headers = [])
{
    return $test->getJson('/api/v1/sync/_probe', [
        ...($key === null ? [] : ['Authorization' => "Bearer {$key}"]),
        'X-SSPOS-Company-Id' => TILL_C, 'X-SSPOS-Branch-Id' => TILL_B, 'X-SSPOS-Register-Id' => TILL_R, 'X-SSPOS-Contract' => '1',
        ...$headers,
    ]);
}

test('the key and the till\'s own ids (through id_map) name our company, branch and till', function () {
    probe($this, $this->key)->assertOk()->assertExactJson(['company' => $this->company->id, 'branch' => $this->branch->id, 'register' => $this->till->id, 'tillBranch' => TILL_B]);
    probe($this, strtolower(str_replace('-', ' ', $this->key)), ['X-SSPOS-Company-Id' => $this->company->id, 'X-SSPOS-Branch-Id' => $this->branch->id])->assertOk();

    expect(SyncKey::withoutCompanyScope()->sole()->last_used_at)->not->toBeNull();
});

test('401 auth.invalid_key for a missing, malformed or unknown key', function (?string $key) {
    probe($this, $key)->assertStatus(401)->assertJsonPath('code', 'auth.invalid_key')
        ->assertJsonStructure(['code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey']);
})->with([null, 'not-a-key', 'SSK-0000-0000-0000-0000-0000-0000-0000-0000']);

test('401 auth.key_revoked for a revoked key, a key replaced over 7 days ago, or a deactivated branch', function () {
    $old = $this->key;
    $new = app(IssueSyncKey::class)->handle($this->branch, SyncKeySource::Admin);
    probe($this, $old)->assertOk();

    $this->travel(8)->days();
    probe($this, $old)->assertStatus(401)->assertJsonPath('code', 'auth.key_revoked');
    probe($this, $new)->assertOk();

    app(RevokeSyncKeys::class)->handle($this->branch);
    probe($this, $new)->assertStatus(401)->assertJsonPath('code', 'auth.key_revoked');

    $this->travelTo(CarbonImmutable::now());
    $fresh = app(IssueSyncKey::class)->handle($this->branch, SyncKeySource::Admin);
    Register::factory()->forBranch(Branch::factory()->forCompany($this->company)->create(['code' => 'BFD']))->create(['code' => '01', 'is_main_till' => true]);
    $this->company->forceFill(['multi_branch' => true, 'max_branches' => 3])->save();
    app(DeactivateBranch::class)->handle($this->branch->fresh());
    probe($this, $fresh)->assertStatus(401)->assertJsonPath('code', 'auth.key_revoked');
});

test('403 auth.wrong_branch when the headers name another branch or company, or no branch', function () {
    probe($this, $this->key, ['X-SSPOS-Branch-Id' => '01K5T0Q8C4000000000000B009'])->assertStatus(403)->assertJsonPath('code', 'auth.wrong_branch');
    probe($this, $this->key, ['X-SSPOS-Company-Id' => '01K5T0Q8C4000000000000C009'])->assertStatus(403)->assertJsonPath('code', 'auth.wrong_branch');
    probe($this, $this->key, ['X-SSPOS-Branch-Id' => ''])->assertStatus(403)->assertJsonPath('code', 'auth.wrong_branch');
});

test('tenant isolation: company B\'s key never opens company A\'s branch, even with A\'s till ids', function () {
    $companyB = Company::factory()->create();
    $branchB = Branch::factory()->forCompany($companyB)->create(['code' => 'BRV']);
    $keyB = app(IssueSyncKey::class)->handle($branchB, SyncKeySource::Admin);

    probe($this, $keyB)->assertStatus(403)->assertJsonPath('code', 'auth.wrong_branch');
    probe($this, $keyB, ['X-SSPOS-Company-Id' => $this->company->id, 'X-SSPOS-Branch-Id' => $this->branch->id])->assertStatus(403);
    probe($this, $keyB, ['X-SSPOS-Company-Id' => $companyB->id, 'X-SSPOS-Branch-Id' => $branchB->id, 'X-SSPOS-Register-Id' => TILL_R])
        ->assertOk()->assertJsonPath('company', $companyB->id)->assertJsonPath('register', null);
});
