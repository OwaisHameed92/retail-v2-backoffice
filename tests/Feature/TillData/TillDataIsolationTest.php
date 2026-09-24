<?php

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Exceptions\MissingCurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\Sale;
use App\Domain\TillData\Models\SaleLine;
use App\Domain\TillData\Sync\Models\AppliedChange;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Feature\TillData\TillFixtures;

/**
 * Company B: same shape of data as the samples, different ids.
 */
function tillCompanyBWithASale(): array
{
    $company = Company::factory()->create(['name' => 'Bravo Mart']);
    $branch = Branch::factory()->forCompany($company)->create(['code' => 'BRV']);
    $till = Register::factory()->forBranch($branch)->create(['code' => '01', 'is_main_till' => true]);

    $sale = TillFixtures::sample('entities/Sale.json');
    $sale = [...$sale, 'id' => '01K5VB00000000000000BBB001', 'companyId' => $company->id, 'branchId' => $branch->id, 'registerId' => $till->id];
    $line = [...TillFixtures::sample('entities/SaleLine.json'), 'id' => '01K5VB00000000000000BBB002', 'companyId' => $company->id, 'saleId' => $sale['id']];
    $product = [...TillFixtures::sample('entities/Product.json'), 'id' => '01K5VB00000000000000BBB003', 'companyId' => $company->id];

    $result = TillFixtures::apply($company, $branch, [
        TillFixtures::envelope('Sale', $sale, 1),
        TillFixtures::envelope('SaleLine', $line, 2),
        TillFixtures::envelope('Product', $product, 3),
    ]);
    expect($result->accepted)->toBe(3);

    return [$company, $sale['id']];
}

beforeEach(function () {
    [$this->companyA, $this->leeds] = TillFixtures::tenant();
    TillFixtures::apply($this->companyA, $this->leeds, TillFixtures::sample('push-request.json'));
    TillFixtures::apply($this->companyA, $this->leeds, TillFixtures::sample('pull-reply.json')['changes']);
    [$this->companyB, $this->saleB] = tillCompanyBWithASale();
});

it('never shows company B\'s till rows to company A\'s user', function () {
    $user = User::factory()->create();
    $this->companyA->users()->attach($user->id, ['role' => CompanyRole::Owner->value, 'is_active' => true]);

    Route::middleware(['web', 'auth', 'company'])->group(function () {
        Route::get('/_test/till/sales', fn () => Sale::query()->pluck('id'));
        Route::get('/_test/till/sales/{id}', fn (string $id) => Sale::findOrFail($id)->receipt_number);
        Route::get('/_test/till/lines', fn () => SaleLine::query()->pluck('company_id')->unique()->values());
        Route::get('/_test/till/products', fn () => ['count' => Product::query()->count()]);
    });

    $this->actingAs($user)->get('/_test/till/sales')->assertOk()->assertExactJson(['01K5VB000000000SR001000482']);
    $this->actingAs($user)->get('/_test/till/sales/'.$this->saleB)->assertNotFound();
    $this->actingAs($user)->get('/_test/till/lines')->assertExactJson([TillFixtures::COMPANY]);
    $this->actingAs($user)->get('/_test/till/products')->assertExactJson(['count' => 2]);
});

it('scopes every till model to the current company and fails closed without one', function () {
    foreach (EntityRegistry::names() as $entity) {
        $def = EntityRegistry::get($entity);

        if ($def->tenancy) {
            continue;
        }

        $model = $def->model;
        expect(fn () => $model::query()->count())->toThrow(MissingCurrentCompany::class);

        app(CurrentCompany::class)->runAs($this->companyA, function () use ($model, $entity) {
            expect($model::query()->where('company_id', '!=', TillFixtures::COMPANY)->count())->toBe(0, $entity);
        });
    }

    app(CurrentCompany::class)->runAs($this->companyB, function () {
        expect(Sale::query()->pluck('id')->all())->toBe([$this->saleB])
            ->and(SaleLine::query()->count())->toBe(1)
            ->and(Sale::find('01K5VB000000000SR001000482'))->toBeNull()
            ->and(AppliedChange::query()->count())->toBe(3);
    });
});

it('refuses a company B row id pushed by company A (no cross-company overwrite)', function () {
    $sale = [...TillFixtures::sample('entities/Sale.json'), 'id' => $this->saleB];
    $result = TillFixtures::apply($this->companyA, $this->leeds, [TillFixtures::envelope('Sale', $sale, 99, ['version' => 50])]);

    expect($result->rejected[0]->code)->toBe('entity.id_taken');
    app(CurrentCompany::class)->runAs($this->companyB, fn () => expect(Sale::findOrFail($this->saleB)->row_version)->toBe(1));
});
