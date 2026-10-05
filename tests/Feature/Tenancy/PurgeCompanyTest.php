<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\PurgeCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

/* tenant:purge / PurgeCompany: one business and everything that belongs to it goes; nothing of another business does. */
beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
});

/** Rows per company_id table for one business. @return array<string, int> */
function rowsOf(string $companyId): array
{
    $counts = [];

    foreach (array_column(Schema::getTables(Schema::getCurrentSchemaName()), 'name') as $table) {
        if (Schema::hasColumn($table, 'company_id') && ($n = DB::table($table)->where('company_id', $companyId)->count()) > 0) {
            $counts[$table] = $n;
        }
    }

    return $counts;
}

/** A business with tills, invoices, a payment, an id map row, its owner and a file. */
function businessWithData(object $test, string $name, string $code): Company
{
    $company = $test->payingTenant($name, 1, $code);
    $test->issuedFor($company);
    $test->pay($company, '10.00', []);
    DB::table('id_map')->insert(['kind' => 'product', 'till_id' => 'T-'.$code, 'portal_id' => 'P-'.$code, 'company_id' => $company->id, 'action' => 'created', 'created_at' => now()]);
    Storage::disk('local')->put("sales-exports/{$company->id}/export.csv", 'a,b');

    return $company;
}

test('purging one business removes all its rows, its own logins and files, and nothing of another business', function () {
    $stoney = businessWithData($this, 'Stoney Mini Mart', 'STN');
    $khan = businessWithData($this, 'Khan Mini Mart', 'KHN');
    $shared = $this->addMember($stoney, CompanyRole::Manager);
    $khan->users()->attach($shared->id, ['role' => CompanyRole::Manager->value, 'is_active' => true]);
    $stoneyOwner = $this->ownerOf($stoney);
    $khanBefore = rowsOf($khan->id);

    expect(rowsOf($stoney->id))->toHaveKeys(['branches', 'registers', 'licences', 'invoices', 'payments', 'billing_accounts', 'id_map', 'company_user']);

    $removed = app(PurgeCompany::class)->handle($stoney->load([]), force: true);

    expect(rowsOf($stoney->id))->toBe([])
        ->and(Company::withTrashed()->find($stoney->id))->toBeNull()
        ->and($removed['companies'])->toBe(1)
        ->and(User::query()->find($stoneyOwner->id))->toBeNull()
        ->and(User::query()->find($shared->id))->not->toBeNull() // still in Khan Mini Mart
        ->and(rowsOf($khan->id))->toBe($khanBefore)
        ->and(Storage::disk('local')->exists("sales-exports/{$stoney->id}/export.csv"))->toBeFalse()
        ->and(Storage::disk('local')->exists("sales-exports/{$khan->id}/export.csv"))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'company.purged')->sole()->meta['name'] ?? null)->toBe('Stoney Mini Mart');
});

test('a business that has traded is refused unless forced', function () {
    $stoney = businessWithData($this, 'Stoney Mini Mart', 'STN');

    expect(app(PurgeCompany::class)->blockers($stoney))->toBe(['1 activated licence'])
        ->and(fn () => app(PurgeCompany::class)->handle($stoney))->toThrow(ValidationException::class, 'has traded')
        ->and(Company::query()->find($stoney->id))->not->toBeNull();
});

test('tenant:purge shows what goes, asks, and removes only that business', function () {
    $stoney = businessWithData($this, 'Stoney Mini Mart', 'STN');
    $khan = businessWithData($this, 'Khan Mini Mart', 'KHN');
    $khanBefore = rowsOf($khan->id);

    // Traded: refused without --force, nothing removed.
    $this->artisan('tenant:purge', ['company' => 'Stoney Mini Mart'])
        ->expectsOutputToContain('has traded')->assertFailed();
    expect(Company::query()->find($stoney->id))->not->toBeNull();

    // Declined: nothing removed.
    $this->artisan('tenant:purge', ['company' => 'Stoney Mini Mart', '--force' => true])
        ->expectsTable(['Table', 'Rows'], collect(app(PurgeCompany::class)->plan($stoney))->map(fn ($n, $t) => [$t, number_format($n)])->values()->all())
        ->expectsConfirmation('Delete Stoney Mini Mart and everything above for good? This cannot be undone.', 'no')
        ->expectsOutput('Nothing removed.')->assertSuccessful();
    expect(Company::query()->find($stoney->id))->not->toBeNull();

    // Confirmed (by id).
    $this->artisan('tenant:purge', ['company' => $stoney->id, '--force' => true])
        ->expectsConfirmation('Delete Stoney Mini Mart and everything above for good? This cannot be undone.', 'yes')
        ->expectsOutputToContain('Stoney Mini Mart removed')->assertSuccessful();

    expect(rowsOf($stoney->id))->toBe([])
        ->and(rowsOf($khan->id))->toBe($khanBefore);
});

test('an unknown or ambiguous business is refused', function () {
    $this->artisan('tenant:purge', ['company' => 'Nobody Stores'])->expectsOutputToContain('No business')->assertFailed();

    $this->payingTenant('Twin Stores', 1, 'TWA');
    $this->payingTenant('Twin Stores 2', 1, 'TWB')->forceFill(['name' => 'Twin Stores'])->save();
    $this->artisan('tenant:purge', ['company' => 'Twin Stores'])->expectsOutputToContain('Use the id instead')->assertFailed();

    expect(Company::query()->where('name', 'Twin Stores')->count())->toBe(2);
});

test('a business that never traded goes without --force', function () {
    $fresh = $this->licensedTenant('Never Traded', 1, 'NVR');

    expect(app(PurgeCompany::class)->blockers($fresh))->toBe([]);
    app(PurgeCompany::class)->handle($fresh);
    expect(rowsOf($fresh->id))->toBe([]);
});
