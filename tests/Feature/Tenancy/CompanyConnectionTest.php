<?php

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\CompanyConnection;
use Illuminate\Support\Facades\DB;

/** Sharding groundwork (docs/scaling.md): the connection that holds a company's data. */
it('puts new companies on the default connection', function () {
    $company = Company::factory()->create();

    expect(DB::table('companies')->where('id', $company->id)->value('data_connection'))->toBe(config('database.default'))
        ->and(CompanyConnection::for($company->fresh()))->toBe(config('database.default'))
        ->and(CompanyConnection::for($company->id))->toBe(config('database.default'));
});

it('resolves a company moved to another configured connection, by model or by id', function () {
    config(['database.connections.tenants_2' => config('database.connections.'.config('database.default'))]);
    $company = Company::factory()->create();
    DB::table('companies')->where('id', $company->id)->update(['data_connection' => 'tenants_2']);

    expect(CompanyConnection::for($company->id))->toBe('tenants_2')
        ->and(CompanyConnection::for($company->fresh()))->toBe('tenants_2');
});

it('falls back to the default for an unknown connection or company', function () {
    $company = Company::factory()->create();
    DB::table('companies')->where('id', $company->id)->update(['data_connection' => 'gone']);

    expect(CompanyConnection::for($company->id))->toBe(config('database.default'))
        ->and(CompanyConnection::for('01K5T0Q8C4000000000000C999'))->toBe(config('database.default'));
});
