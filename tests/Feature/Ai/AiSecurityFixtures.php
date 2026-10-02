<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Contracts\AiTool;
use App\Domain\Ai\Enums\ToolAudience;
use App\Domain\Ai\Tools\ToolRegistry;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Purchasing\ReorderFixtures as R;
use Tests\Feature\Reporting\BusinessDashboardHelpers as H;
use Tests\Feature\TillData\TillFixtures as T;

/**
 * AI security review test data (on top of PortalAssistantHelpers): both businesses get products, a supplier, a
 * customer who owes, a till user with a PIN, fob and pay rate, clock-ins and stock, each with names that must never
 * cross over. Kirkgate's Bradford shop has its own customer and staff ("Bradford-only") for the one-shop checks.
 * `input()` builds a valid call of ANY registered tool from its schema, so new tools are covered automatically.
 */
final class AiSecurityFixtures
{
    public const A_CUSTOMER = '01K5T0Q8C40000000000CUSTA1';

    public const A_BRADFORD_CUSTOMER = '01K5T0Q8C40000000000CUSTA2';

    public const A_STAFF = '01K5T0Q8C40000000000USRAAA';

    public const A_BRADFORD_STAFF = '01K5T0Q8C40000000000USRAAB';

    public const B_PRODUCT = '01K5T0Q8C40000000000ZEBRA1';

    public const B_SUPPLIER = '01K5T0Q8C40000000000ZEBRA2';

    public const B_CUSTOMER = '01K5T0Q8C40000000000ZEBRA3';

    public const B_STAFF = '01K5T0Q8C40000000000ZEBRA4';

    /** Personal data that must never reach the model. */
    public const PII = ['RFID-77001234', 'pinhash-secret-a', '13.47', '07700 900111', 'jane.owes@example.test', '1 Secret Street', 'LS1 9ZZ'];

    /** Values of the other business (Other Stores) that must never reach Kirkgate's model. */
    public const B_MARKERS = ['Zebra', 'Zelda', 'Zane', 'Other Stores', 'Other shop'];

    /** Values of Kirkgate's Bradford shop that a Leeds-only manager's model must never see. */
    public const BRADFORD_MARKERS = ['Bradford', 'Brenda', T::BRADFORD, T::BRADFORD_TILL];

    public static function seed(Company $kirkgate, Branch $leeds, Branch $bradford, Company $other, Branch $otherShop): void
    {
        F::catalogue($kirkgate);
        R::scenario($kirkgate, $leeds, $bradford);
        H::shift($kirkgate->id, $leeds->id, '01K5T0Q8C40000000000SHFA01', '2026-09-22 18:00:00', '-2.50');
        self::customer($kirkgate, $leeds, self::A_CUSTOMER, 'Jane Owes', '25.00');
        self::customer($kirkgate, $bradford, self::A_BRADFORD_CUSTOMER, 'Brenda Tab', '30.00');
        self::staff($kirkgate, $leeds, T::TILL_1, self::A_STAFF, 'Sam Leeds', ['pin_hash' => 'pinhash-secret-a', 'rfid' => 'RFID-77001234', 'rate_per_hour' => '13.47']);
        self::staff($kirkgate, $bradford, T::BRADFORD_TILL, self::A_BRADFORD_STAFF, 'Brenda Clock', []);
        DB::table('branches')->where('id', $leeds->id)->update(['phone' => '07700 900111', 'address' => '1 Secret Street', 'postcode' => 'LS1 9ZZ']);

        $otherTill = Register::withoutCompanyScope()->where('branch_id', $otherShop->id)->firstOrFail();
        DB::table('suppliers')->insert(['id' => self::B_SUPPLIER, 'company_id' => $other->id, 'name' => 'Zebra Wholesale', 'is_active' => true]);
        DB::table('products')->insert(['id' => self::B_PRODUCT, 'company_id' => $other->id, 'name' => 'Zebra Secret Lager', 'sku' => 'ZEBRA-1',
            'sell_price' => '9.99', 'cost_price' => '4.0000', 'is_active' => true, 'track_stock' => true]);
        DB::table('product_suppliers')->insert(['id' => '01K5T0Q8C40000000000ZEBRA5', 'company_id' => $other->id, 'product_id' => self::B_PRODUCT,
            'supplier_id' => self::B_SUPPLIER, 'case_qty' => 6, 'case_cost' => '24.0000', 'is_preferred' => true]);
        R::stock($other, $otherShop, self::B_PRODUCT, '1', reorderPoint: '20');
        self::customer($other, $otherShop, self::B_CUSTOMER, 'Zelda Zebra', '99.99');
        self::staff($other, $otherShop, $otherTill->id, self::B_STAFF, 'Zane Zebra', ['rate_per_hour' => '21.00']);
    }

    /**
     * Ids to feed the tools: Kirkgate's own ('a'), Kirkgate's with Bradford as the shop ('bradford', for a Leeds-only
     * manager) or the other business's ('b', an IDOR attempt).
     *
     * @return array{shop: string, product: string, supplier: string, customer: string, till: string}
     */
    public static function ids(string $which, Branch $otherShop): array
    {
        return match ($which) {
            'a' => ['shop' => T::LEEDS, 'product' => F::COLA, 'supplier' => F::SUPPLIER, 'customer' => self::A_CUSTOMER, 'till' => T::TILL_1],
            'bradford' => ['shop' => T::BRADFORD, 'product' => F::COLA, 'supplier' => F::SUPPLIER, 'customer' => self::A_BRADFORD_CUSTOMER, 'till' => T::BRADFORD_TILL],
            default => ['shop' => $otherShop->id, 'product' => self::B_PRODUCT, 'supplier' => self::B_SUPPLIER, 'customer' => self::B_CUSTOMER,
                'till' => Register::withoutCompanyScope()->where('branch_id', $otherShop->id)->value('id')],
        };
    }

    /** @return list<AiTool> every registered tenant tool, including ones added after this test was written */
    public static function tenantTools(): array
    {
        return array_values(array_filter(app(ToolRegistry::class)->all(), fn (AiTool $t) => $t->audience() === ToolAudience::Tenant));
    }

    /**
     * A call of the tool built from its JSON schema: every id-like property gets the given ids, enums prefer a wide
     * reading (last 7 days, split by shop), other required properties get a plain valid value.
     *
     * @param  array<string, string>  $ids
     * @return array<string, mixed>
     */
    public static function input(AiTool $tool, array $ids): array
    {
        return (array) self::value('', $tool->inputSchema(), $ids, true);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, string>  $ids
     */
    private static function value(string $name, array $schema, array $ids, bool $required): mixed
    {
        $type = is_array($schema['type'] ?? null) ? $schema['type'][0] : ($schema['type'] ?? 'string');
        $idKey = self::idKey($name);

        if ($type === 'object') {
            $out = [];
            $wanted = (array) ($schema['required'] ?? []);

            foreach ((array) ($schema['properties'] ?? []) as $prop => $child) {
                $isRequired = in_array($prop, $wanted, true);
                if ($isRequired || self::idKey((string) $prop) !== null || isset($child['enum'])) {
                    $out[$prop] = self::value((string) $prop, (array) $child, $ids, $isRequired);
                }
            }

            return $out;
        }

        if ($type === 'array') {
            $item = self::value(rtrim($name, 's'), (array) ($schema['items'] ?? []), $ids, true);

            return [$item];
        }

        if (isset($schema['enum'])) {
            $enum = (array) $schema['enum'];
            foreach (['last7Days', 'shop'] as $preferred) {
                if (in_array($preferred, $enum, true)) {
                    return $preferred;
                }
            }

            return $enum[0];
        }

        return match (true) {
            $idKey !== null => $ids[$idKey],
            $type === 'integer' => min((int) ($schema['maximum'] ?? 5), max((int) ($schema['minimum'] ?? 1), 5)),
            $type === 'number' => 1,
            $type === 'boolean' => false,
            in_array($name, ['search', 'query', 'q'], true) => 'Cola',
            in_array($name, ['from', 'date'], true) => '2026-09-17',
            $name === 'to' => '2026-09-23',
            default => 'Security review',
        };
    }

    private static function idKey(string $name): ?string
    {
        return match (true) {
            in_array($name, ['shop_id', 'branch_id', 'shop', 'branch'], true) => 'shop',
            in_array($name, ['product_id', 'product_ids', 'product'], true) => 'product',
            in_array($name, ['supplier_id', 'supplier'], true) => 'supplier',
            in_array($name, ['customer_id', 'customer'], true) => 'customer',
            in_array($name, ['register_id', 'till_id', 'till'], true) => 'till',
            default => null,
        };
    }

    private static function customer(Company $company, Branch $shop, string $id, string $name, string $balance): void
    {
        DB::table('customers')->insert(['id' => $id, 'company_id' => $company->id, 'name' => $name, 'card_no' => 'CARD-'.substr($id, -3),
            'balance' => $balance, 'credit_limit' => '10.00', 'phone' => '07700 900111', 'email' => 'jane.owes@example.test',
            'address' => '1 Secret Street', 'dob' => '1980-01-01', 'is_active' => true]);
        DB::table('customer_transactions')->insert(['id' => substr($id, 0, 20).'TX'.substr($id, -4), 'company_id' => $company->id, 'branch_id' => $shop->id,
            'customer_id' => $id, 'type' => 'charge', 'amount' => $balance, 'at' => '2026-09-22 10:00:00']);
    }

    /** @param array<string, string> $extra */
    private static function staff(Company $company, Branch $shop, string $till, string $id, string $name, array $extra): void
    {
        DB::table('till_users')->insert(['id' => $id, 'company_id' => $company->id, 'name' => $name, 'is_active' => true, ...$extra]);

        foreach ([['in', '2026-09-22 09:00'], ['out', '2026-09-22 17:00']] as $i => [$type, $at]) {
            DB::table('clock_events')->insert(['id' => substr($id, 0, 20).'CL'.substr($id, -3).$i, 'company_id' => $company->id, 'branch_id' => $shop->id,
                'register_id' => $till, 'user_id' => $id, 'type' => $type, 'note' => '',
                'at' => CarbonImmutable::parse($at, 'Europe/London')->utc()->format('Y-m-d H:i:s')]);
        }
    }
}
