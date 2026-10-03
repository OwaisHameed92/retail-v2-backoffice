<?php

use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Enums\IdMapAction;
use App\Domain\Sync\Models\IdMapping;
use App\Domain\Sync\Support\IdTranslator;
use App\Domain\Tenancy\Models\Company;

/**
 * 2026-10-04, test server: a second till installed on its own (not joined to the main till) brought its own company
 * and branch ids for the same shop. Hello then answered the main till with the second till's branch id, so the main
 * till treated its key as another shop's and stopped pushing. Hello and pull must echo the caller's own ids.
 */
test('toTill answers with the calling till\'s own ids when two installs mapped the same shop', function () {
    $company = Company::factory()->create();
    $branchId = (string) str()->ulid();
    $row = fn (string $kind, string $tillId, string $portalId, IdMapAction $action, string $install) => IdMapping::withoutCompanyScope()->create([
        'kind' => $kind, 'till_id' => $tillId, 'portal_id' => $portalId, 'company_id' => $company->id,
        'branch_id' => $branchId, 'action' => $action, 'install_id' => $install,
    ]);

    $row('company', '01M41RWTW74SMSA90K6SK5SBT9', $company->id, IdMapAction::Adopted, 'MAIN');
    $row('branch', '01M41RWTYNGEWDJQC7EZRV456G', $branchId, IdMapAction::Adopted, 'MAIN');
    $row('company', '01M41V7XGY9G795558K6V8YNG7', $company->id, IdMapAction::Aliased, 'SECOND');
    $row('branch', '01M41V7XHAXY4FXSE77QP9EFPY', $branchId, IdMapAction::Adopted, 'SECOND');

    $main = IdTranslator::forCompany($company->id)->preferring(['01M41RWTW74SMSA90K6SK5SBT9', '01M41RWTYNGEWDJQC7EZRV456G']);
    $second = IdTranslator::forCompany($company->id)->preferring(['01M41V7XGY9G795558K6V8YNG7', '01M41V7XHAXY4FXSE77QP9EFPY']);

    expect($main->toTill(IdKind::Branch, $branchId, $branchId))->toBe('01M41RWTYNGEWDJQC7EZRV456G')
        ->and($main->toTill(IdKind::Company, $company->id, $branchId))->toBe('01M41RWTW74SMSA90K6SK5SBT9')
        ->and($second->toTill(IdKind::Branch, $branchId, $branchId))->toBe('01M41V7XHAXY4FXSE77QP9EFPY')
        ->and($second->toTill(IdKind::Company, $company->id, $branchId))->toBe('01M41V7XGY9G795558K6V8YNG7');
});
