<?php

use App\Domain\TillData\Actions\GenerateTillEntities;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Generator\Php;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\TillData\TillFixtures;

function tillSchemaEntities(): array
{
    return array_map(
        fn (string $file) => basename($file, '.schema.json'),
        glob(base_path(TillFixtures::CONTRACT.'/schemas/entities/*.schema.json')),
    );
}

it('is idempotent: regenerating changes no file', function () {
    $result = app(GenerateTillEntities::class)->handle(write: false);

    expect($result['changed'])->toBe([])
        ->and($result['deleted'])->toBe([])
        ->and($result['unchanged'])->toBeGreaterThan(200);
});

it('has a registry entry, a table with every mapped column, and a final generated model for every schema entity', function () {
    $entities = tillSchemaEntities();

    expect($entities)->toHaveCount(123)
        ->and(EntityRegistry::names())->toEqualCanonicalizing($entities);

    foreach ($entities as $entity) {
        $def = EntityRegistry::get($entity);

        expect(Schema::hasTable($def->table))->toBeTrue("{$entity}: table {$def->table} is missing");

        if ($def->tenancy) {
            continue;
        }

        $columns = Schema::getColumnListing($def->table);
        expect(array_diff($def->columns, $columns))->toBe([], "{$entity}: missing columns")
            ->and(class_exists($def->model))->toBeTrue()
            ->and((new ReflectionClass($def->model))->isFinal())->toBeTrue()
            ->and((string) file_get_contents((new ReflectionClass($def->model))->getFileName()))->toContain(Php::GENERATED_TAG)
            ->and((new $def->model)->getTable())->toBe($def->table);
    }
});

it('uses exactly the ownership in samples/ownership.json', function () {
    $ownership = TillFixtures::sample('ownership.json')['entities'];

    foreach (EntityRegistry::names() as $entity) {
        expect(EntityRegistry::get($entity)->ownership)->toBe($ownership[$entity], $entity);
    }

    // Listed by the till but not syncable yet (not Entity-derived, README section 16): no schema, no table.
    expect(array_values(array_diff(array_keys($ownership), EntityRegistry::names())))->toBe(['RolePermission', 'Setting']);
});

it('generates PHP backed enums with exactly the values of samples/enums.json', function () {
    foreach (TillFixtures::sample('enums.json') as $name => $values) {
        $class = "App\\Domain\\TillData\\Enums\\{$name}";

        expect(enum_exists($class))->toBeTrue($name)
            ->and(array_map(fn (BackedEnum $case) => $case->value, $class::cases()))->toBe($values);
    }
});

it('maps company, branch and register rows onto the tenancy tables without generating models for them', function () {
    expect(EntityRegistry::get('Company')->table)->toBe('companies')
        ->and(EntityRegistry::get('Branch')->table)->toBe('branches')
        ->and(EntityRegistry::get('Register')->table)->toBe('registers')
        ->and(file_exists(app_path('Domain/TillData/Models/Company.php')))->toBeFalse()
        ->and(EntityRegistry::get('Licence')->table)->toBe('till_licences')
        ->and(Schema::getColumnListing('till_licences'))->toContain('licence_key_hash', 'licence_key_last4')
        ->not->toContain('licence_key');
});

it('classifies scopes and parents as the README describes', function () {
    expect(EntityRegistry::get('Sale')->scope)->toBe('register')
        ->and(EntityRegistry::get('CustomerOrder')->scope)->toBe('branch')
        ->and(EntityRegistry::get('Product')->scope)->toBe('company')
        ->and(EntityRegistry::get('FinancialYear')->scope)->toBe('sender')
        ->and(EntityRegistry::get('SaleLine')->scope)->toBe('child')
        ->and(EntityRegistry::get('SaleLine')->parent)->toMatchArray(['entity' => 'Sale', 'column' => 'sale_id'])
        ->and(EntityRegistry::get('SaleLine')->scopeColumns)->toBe(['branch_id', 'register_id'])
        ->and(EntityRegistry::get('CustomerOrderPayment')->scopeColumns)->toBe(['branch_id'])
        ->and(EntityRegistry::get('JournalLine')->scopeColumns)->toBe(['branch_id', 'register_id'])
        ->and(EntityRegistry::get('Sale')->children)->toBe(['SaleLine', 'SalePayment', 'SaleVat']);
});

it('stores money as decimal(12,2), costs and quantities as decimal(14,4)', function () {
    $fields = EntityRegistry::get('SaleLine')->fields;

    expect($fields['unitPrice']->type)->toBe('money')
        ->and($fields['lineTotal']->type)->toBe('money')
        ->and($fields['costAtSale']->type)->toBe('cost')
        ->and($fields['qty']->type)->toBe('quantity')
        ->and($fields['vatPercentage']->type)->toBe('percent')
        ->and(EntityRegistry::get('Sale')->fields['receiptJson']->type)->toBe('longText')
        ->and(EntityRegistry::get('Sale')->derived)->toContain('isCompleted', 'isDeleted', 'domainEvents')
        ->and(EntityRegistry::get('CustomerOrder')->derived)->toContain('balanceDue')
        ->and(EntityRegistry::get('Product')->derived)->toContain('priceIncVat', 'barcodes', 'primaryBarcode');
});
