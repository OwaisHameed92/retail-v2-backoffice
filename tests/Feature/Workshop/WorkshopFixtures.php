<?php

namespace Tests\Feature\Workshop;

use App\Domain\Licensing\Enums\AddOn;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Actions\SaveTillSetting;
use App\Domain\TillData\Enums\SettingScope;
use App\Domain\Workshop\Actions\IssueWorkshopApiKey;
use App\Domain\Workshop\Models\WorkshopConnection;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures as T;
use Tests\TestCase;

/**
 * W5 BikeBook connector test helpers: the switch, the shop's add-on and connection, the connector calls (API key
 * header + tenant-id), and the till's pushes of the CustomerOrder / payments / stock that follow a WorkshopOrder.
 *
 * Catalogue (PurchasingFixtures): VAT V001 standard 20 % (default) and V002 zero, products P001 (water, 0 %) and P002
 * (cola, 20 %), category E001.
 */
final class WorkshopFixtures
{
    public const BASE = '/connectors/bikebook/v1/api';

    public const WEBHOOK_URL = 'https://api.bikebook.co.uk/v2/Webhook/sspos';

    public const SECRET = 'whsec-bikebook-test-0123456789';

    private static int $seq = 7000;

    public static function on(): void
    {
        config([
            'workshop.connector' => true,
            'workshop.bikebook.client_id' => 'bikebook-test-client',
            'workshop.bikebook.client_secret' => 'bikebook-test-secret-value',
            'workshop.bikebook.redirect_uris' => ['https://workshop.bikebook.co.uk/oauth/callback'],
        ]);
    }

    public static function addOn(Branch $branch): void
    {
        $branch->forceFill(['licence_add_ons' => [AddOn::WorkshopIntegration->value]])->save();
    }

    /** Switch on, catalogue, scope `all` for Leeds, the add-on and an API key for Leeds. Returns the key. */
    public static function connected(SyncApiFixtures $sync, bool $catalogue = true): string
    {
        self::on();

        if ($catalogue) {
            F::catalogue($sync->company);
        }

        self::setting($sync->company, $sync->leeds, 'workshop.product_scope', 'all');
        self::addOn($sync->leeds);

        return app(IssueWorkshopApiKey::class)->handle($sync->leeds);
    }

    public static function setting(Company $company, Branch $branch, string $key, string $value): void
    {
        app(SaveTillSetting::class)->handle($company, SettingScope::Branch, $branch, $key, $value);
    }

    public static function connection(Branch $branch): WorkshopConnection
    {
        return WorkshopConnection::withoutCompanyScope()->where('branch_id', $branch->id)->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $key, ?Branch $branch = null): array
    {
        return ['ApiKey' => $key, 'Accept' => 'application/json', ...($branch !== null ? ['tenant-id' => $branch->id] : [])];
    }

    /**
     * @param  array<mixed>  $body
     * @param  array<string, string>  $headers
     */
    public static function call(TestCase $test, string $method, string $path, string $key, array $body = [], array $headers = []): TestResponse
    {
        return $test->json($method, self::BASE.$path, $body, [...self::headers($key), ...$headers]);
    }

    /**
     * A BikeBook "Send to POS" body: a part (cola), labour and a custom line.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function order(Branch $branch, array $overrides = []): array
    {
        return [
            'orderNumber' => 'INV#1042 [abcd]',
            'store' => $branch->id,
            'customer' => ['firstName' => 'Ali', 'lastName' => 'Khan', 'phone' => '07700 900123', 'emailAddress' => 'ali@example.co.uk'],
            'lines' => [
                ['id' => 'l1', 'variantId' => F::COLA, 'name' => 'Brake pads', 'quantity' => 2, 'paidPrice' => 6.00, 'taxRate' => F::VAT_STD, 'lineType' => 'Product'],
                ['id' => 'l2', 'variantId' => '', 'name' => 'Gear tune', 'quantity' => 1, 'paidPrice' => 45.00, 'taxRate' => F::VAT_STD, 'lineType' => 'Labour'],
                ['id' => 'l3', 'variantId' => null, 'name' => 'Inner tube fitting', 'quantity' => 1, 'paidPrice' => 8.50, 'taxRate' => F::VAT_STD, 'lineType' => 'Custom'],
            ],
            ...$overrides,
        ];
    }

    /**
     * The till's push of the CustomerOrder it made from a pulled WorkshopOrder (same id), as Leeds till 1.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function pushCustomerOrder(SyncApiFixtures $sync, string $orderId, array $changes = [], int $version = 1): TestResponse
    {
        $payload = [
            ...T::sample('entities/CustomerOrder.json'),
            'id' => $orderId, 'companyId' => T::COMPANY, 'branchId' => T::LEEDS, 'reference' => 'WS-1042', 'status' => 'ordered',
            'goodsTotal' => 65.5, 'paidTotal' => 0.0, 'source' => 'workshop', 'externalProvider' => 'bikebook', 'externalOrderId' => $orderId,
            'externalReference' => '1042', 'idempotencyKey' => $orderId, 'rowVersion' => $version, 'updatedAt' => '2026-11-08T10:0'.$version.':00Z',
            ...$changes,
        ];

        return $sync->push([T::envelope('CustomerOrder', $payload, ++self::$seq, ['op' => $version === 1 ? 'I' : 'U'])]);
    }

    /** A CustomerOrderPayment the till pushes for an order (deposit, collection or refund). */
    public static function pushPayment(SyncApiFixtures $sync, string $orderId, string $kind, float $amount, ?string $saleId = null): TestResponse
    {
        $id = Ulid::new();
        $payload = [
            'orderId' => $orderId, 'saleId' => $saleId ?? Ulid::new(), 'kind' => $kind, 'amount' => $amount, 'at' => '2026-11-08T11:00:00Z',
            'userId' => '01K5T0Q8C4000000000000A001', 'id' => $id, 'companyId' => T::COMPANY, 'branchId' => T::LEEDS,
            'createdAt' => '2026-11-08T11:00:00Z', 'updatedAt' => '2026-11-08T11:00:00Z', 'rowVersion' => 1, 'deletedAt' => null, 'isDeleted' => false, 'domainEvents' => [],
        ];

        return $sync->push([T::envelope('CustomerOrderPayment', $payload, ++self::$seq)]);
    }

    /** The till's BranchProduct for Leeds (stock and price override). */
    public static function pushStock(SyncApiFixtures $sync, string $productId, float $onHand, float $reserved = 0.0, ?float $priceOverride = null, ?string $id = null): TestResponse
    {
        $id ??= Ulid::new();
        $payload = [
            'productId' => $productId, 'isActive' => true, 'tilePosition' => null, 'tileColourHex' => null, 'priceOverride' => $priceOverride,
            'qtyOnHand' => $onHand, 'qtyReserved' => $reserved, 'qtyAvailable' => $onHand - $reserved, 'isStockTracked' => null,
            'reorderPoint' => null, 'minQty' => null, 'maxQty' => null, 'branchId' => T::LEEDS, 'id' => $id, 'companyId' => T::COMPANY,
            'createdAt' => '2026-11-08T09:00:00Z', 'updatedAt' => '2026-11-08T09:30:00Z', 'rowVersion' => 1, 'deletedAt' => null, 'isDeleted' => false, 'domainEvents' => [],
        ];

        return $sync->push([T::envelope('BranchProduct', $payload, ++self::$seq)]);
    }

    /** BikeBook's webhook on us (all four events). */
    public static function webhook(TestCase $test, string $key): TestResponse
    {
        return self::call($test, 'POST', '/Webhook', $key, [
            'id' => 'wh_1', 'url' => self::WEBHOOK_URL, 'secret' => self::SECRET, 'name' => 'BikeBook',
            'subscriptions' => ['order.statuschanged', 'inventory.updated', 'product.updated', 'customer.updated'], 'enabled' => true,
        ]);
    }

    /**
     * The WorkshopOrder rows of a pull.
     *
     * @return list<array<string, mixed>>
     */
    public static function workshopRows(TestResponse $pull): array
    {
        return array_values(array_filter((array) $pull->json('changes'), fn (array $c) => $c['entity'] === 'WorkshopOrder'));
    }
}
