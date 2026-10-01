<?php

use App\Domain\Accounts\Export\AccountingExportMapping;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Accounts\AccountsFixtures as A;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\TillData\TillFixtures;

/*
 * Gap #8: journals exported for Xero, QuickBooks Online, Sage 50 and Sage Accounting. Each format is golden-file
 * tested on the standard book (September 2026, every shop); the mapping changes the codes; only owners and
 * accountants may export; one business never sees another's journals or mapping.
 */

const EXPORT_SEPT = 'from=2026-09-01&to=2026-09-30&shop=all';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'Europe/London'));
    $this->withoutVite();
    [$this->company] = TillFixtures::tenant();
    A::book($this->company->id);
    DB::table('vat_rates')->insert(['id' => 'VATS0000000000000000000001', 'company_id' => $this->company->id, 'name' => 'Standard', 'code' => 'S', 'percentage' => '20.00', 'origin_branch_id' => TillFixtures::LEEDS]);
    DB::table('journal_lines')->where('company_id', $this->company->id)->where('account_code', '4000')->update(['vat_rate_id' => 'VATS0000000000000000000001']);
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $this->accountant = C::member($this->company, CompanyRole::Accountant);

    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $shop = Branch::factory()->forCompany($this->other)->create(['name' => 'Other shop']);
    A::chart($this->other->id, $shop->id, 'O');
    A::entry($this->other->id, $shop->id, '01K5T0Q8C4000000000000JOTH', '2026-09-15', 'Sale', [['1000', '999.00', '0.00'], ['4000', '0.00', '999.00']]);
    (new AccountingExportMapping)->forceFill(['company_id' => $this->other->id, 'target' => 'xero', 'kind' => 'account', 'our_code' => '4000', 'their_code' => 'OTHER-4000'])->save();
});

function golden(string $name, string $csv): void
{
    $path = __DIR__.'/golden/'.$name;

    if (getenv('UPDATE_GOLDEN') === '1') {
        file_put_contents($path, $csv);
    }

    expect($csv)->toBe(file_get_contents($path));
}

test('guests sign in; staff and managers get 403; owners and accountants may preview, download and map', function () {
    $urls = ['/app/accounts/export', '/app/accounts/export/download', '/app/accounts/export/mappings'];

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(C::member($this->company, CompanyRole::Staff))->get($url)->assertForbidden();
        $this->actingAs(C::member($this->company, CompanyRole::Manager))->get($url)->assertForbidden();
        $this->actingAs($this->owner)->get($url)->assertOk();
        $this->actingAs($this->accountant)->get($url)->assertOk();
        auth()->logout();
    }

    $this->put('/app/accounts/export/mappings', ['target' => 'xero'])->assertRedirect(route('login'));
    $this->actingAs(C::member($this->company, CompanyRole::Manager))->put('/app/accounts/export/mappings', ['target' => 'xero'])->assertForbidden();
    $this->actingAs(C::member($this->company, CompanyRole::Accountant, TillFixtures::LEEDS))->put('/app/accounts/export/mappings', ['target' => 'xero', 'accounts' => ['4000' => '201']])->assertForbidden();
    expect(CompanyRole::Accountant->can('accounts.export'))->toBeTrue()->and(CompanyRole::Manager->can('accounts.export'))->toBeFalse();
});

test('each package\'s file matches its golden file', function (string $target, string $grouping) {
    $response = $this->actingAs($this->accountant)->get('/app/accounts/export/download?'.EXPORT_SEPT."&target={$target}&grouping={$grouping}")
        ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', "attachment; filename=\"journals-{$target}-2026-09-01-to-2026-09-30.csv\"");

    golden("{$target}-{$grouping}.csv", $response->getContent());
    expect($response->getContent())->not->toContain('999.00')->not->toContain('OTHER-4000');
    expect(AuditLog::query()->where('action', 'accounts.exported')->where('company_id', $this->company->id)->count())->toBe(1);
})->with([
    'xero daily' => ['xero', 'daily'],
    'xero period' => ['xero', 'period'],
    'quickbooks daily' => ['quickbooks', 'daily'],
    'sage 50 daily' => ['sage50', 'daily'],
    'sage accounting daily' => ['sageAccounting', 'daily'],
]);

test('every exported journal balances and the preview shows the codes it will use', function () {
    $this->actingAs($this->owner)->get('/app/accounts/export?'.EXPORT_SEPT.'&target=sage50')->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/accounts/export')
            ->where('totals.journals', 5)
            ->where('totals.debits', fn ($d) => $d === $page->toArray()['props']['totals']['credits'])
            ->where('journals.0.lines', fn ($lines) => collect($lines)->every(fn ($l) => $l['mapped']))
            ->where('journals.0.lines', fn ($lines) => collect($lines)->firstWhere('ourCode', '4000')['taxCode'] === 'T1')
            ->where('unmapped', [])
            ->has('sample.rows'));

    A::entry($this->company->id, TillFixtures::LEEDS, '01K5T0Q8C4000000000000JCHA', '2026-09-20', 'Sale', [['1000', '1.00', '0.00'], ['2250', '0.00', '1.00']]);
    $this->actingAs($this->owner)->get('/app/accounts/export?'.EXPORT_SEPT.'&target=xero')
        ->assertInertia(fn ($page) => $page->where('unmapped.0.code', '2250'));
});

test('the business\'s own mapping changes the codes; blank goes back to the default; another business\'s mapping is never used', function () {
    $this->actingAs($this->accountant)->get('/app/accounts/export/mappings?target=xero')->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/accounts/export-mappings')
            ->where('accounts', fn ($rows) => collect($rows)->firstWhere('code', '4000')['default'] === '200' && collect($rows)->firstWhere('code', '4000')['theirs'] === '')
            ->where('vat', fn ($rows) => collect($rows)->pluck('code')->contains('S')));

    $this->actingAs($this->accountant)->put('/app/accounts/export/mappings', [
        'target' => 'xero', 'accounts' => ['4000' => '201', '1000' => ''], 'vat' => ['S' => ['sales' => '20% (VAT on Income)', 'purchases' => '']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $csv = $this->actingAs($this->accountant)->get('/app/accounts/export/download?'.EXPORT_SEPT.'&target=xero')->getContent();
    expect($csv)->toContain(',201,"20% (VAT on Income)",-100.00,')->toContain(',090,"No VAT",')
        ->and(AccountingExportMapping::withoutCompanyScope()->where('company_id', $this->company->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'accounts.export_mapping_updated')->count())->toBe(1);

    $this->actingAs($this->accountant)->put('/app/accounts/export/mappings', ['target' => 'xero', 'accounts' => ['4000' => '200']])->assertRedirect();
    expect(AccountingExportMapping::withoutCompanyScope()->where('company_id', $this->company->id)->where('kind', 'account')->count())->toBe(0)
        ->and(AccountingExportMapping::withoutCompanyScope()->where('company_id', $this->other->id)->value('their_code'))->toBe('OTHER-4000');
});

test('a one-shop accountant exports only their shop', function () {
    $leeds = C::member($this->company, CompanyRole::Accountant, TillFixtures::LEEDS);

    $csv = $this->actingAs($leeds)->get('/app/accounts/export/download?'.EXPORT_SEPT.'&target=xero')->getContent();

    expect($csv)->toContain('Leeds')->not->toContain('Bradford');
});
