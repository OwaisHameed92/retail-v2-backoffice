<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use App\Domain\Workshop\Enums\ConnectionStatus;
use App\Domain\Workshop\Enums\WebhookEvent;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Queries\ConnectorCustomers;
use App\Domain\Workshop\Queries\ConnectorInventory;
use App\Domain\Workshop\Queries\ConnectorProducts;
use App\Domain\Workshop\Support\WorkshopSwitch;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * After a shop's push is stored (PushChanges), the webhooks it causes (WORKSHOP-BIKEBOOK.md §3.4, §3.8):
 *
 * - CustomerOrder / CustomerOrderPayment rows → ReportWorkshopOrders (`order.statuschanged`: paid, cancelled, refunds).
 * - BranchProduct / BranchPrice rows of the shop → `inventory.updated` for the in-scope variants (scope settings and
 *   `workshopExposure` honoured), debounced to one per 30 s.
 * - Optional, when BikeBook subscribed and the push touched at most 50 of them: `product.updated` (in-scope products)
 *   and `customer.updated` (incl. anonymised customers, with empty personal fields).
 *
 * Nothing runs while WORKSHOP_CONNECTOR is off or the shop has no connection carrying traffic (one indexed check).
 * Never fails the push: an error is reported and the push answers as it would have.
 */
final class WatchWorkshopPush
{
    private const OPTIONAL_LIMIT = 50;

    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly ReportWorkshopOrders $orders,
        private readonly QueueWorkshopWebhook $queue,
        private readonly ConnectorInventory $inventory,
        private readonly ConnectorProducts $products,
    ) {}

    /**
     * @param  iterable<mixed>  $changes  the push's envelopes as decoded
     */
    public function handle(Company $company, Branch $branch, iterable $changes): void
    {
        if (! WorkshopSwitch::enabled()) {
            return;
        }

        try {
            $connection = WorkshopConnection::withoutCompanyScope()->where('company_id', $company->id)->where('branch_id', $branch->id)
                ->whereIn('status', [ConnectionStatus::Connected->value, ConnectionStatus::Failed->value])->first();

            if ($connection !== null) {
                $this->tenancy->runAs($company, fn () => $this->react($connection, self::ids($changes)));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, list<string>>  $ids  entity => entity ids
     */
    private function react(WorkshopConnection $connection, array $ids): void
    {
        $paymentOrders = ($ids['CustomerOrderPayment'] ?? []) === [] ? [] : DB::table('customer_order_payments')
            ->where('company_id', $connection->company_id)->whereIn('id', $ids['CustomerOrderPayment'])->pluck('order_id')->map(fn ($id) => (string) $id)->all();
        $this->orders->handle($connection, array_values(array_unique([...($ids['CustomerOrder'] ?? []), ...$paymentOrders])));

        $stockProducts = [];

        foreach (['BranchProduct' => 'branch_products', 'BranchPrice' => 'branch_prices'] as $entity => $table) {
            if (($ids[$entity] ?? []) !== []) {
                $stockProducts = [...$stockProducts, ...DB::table($table)->where('company_id', $connection->company_id)
                    ->where('branch_id', $connection->branch_id)->whereIn('id', $ids[$entity])->pluck('product_id')->map(fn ($id) => (string) $id)->all()];
            }
        }

        $this->queue->inventory($connection, $this->inventory->inScope($connection, array_values(array_unique($stockProducts))));

        $products = $ids['Product'] ?? [];

        if ($products !== [] && count($products) <= self::OPTIONAL_LIMIT && $connection->wants(WebhookEvent::ProductUpdated)) {
            foreach ($products as $id) {
                $model = $this->products->one($connection, $id);

                if ($model !== null && $model['enabled'] === true) {
                    $this->queue->handle($connection, WebhookEvent::ProductUpdated, $model);
                }
            }
        }

        $customers = $ids['Customer'] ?? [];

        if ($customers !== [] && count($customers) <= self::OPTIONAL_LIMIT && $connection->wants(WebhookEvent::CustomerUpdated)) {
            foreach (Customer::query()->whereIn('id', $customers)->get() as $customer) {
                $this->queue->handle($connection, WebhookEvent::CustomerUpdated, ConnectorCustomers::model($customer));
            }
        }
    }

    /**
     * @param  iterable<mixed>  $changes
     * @return array<string, list<string>>
     */
    private static function ids(iterable $changes): array
    {
        $wanted = ['CustomerOrder', 'CustomerOrderPayment', 'BranchProduct', 'BranchPrice', 'Product', 'Customer'];
        $ids = [];

        foreach ($changes as $change) {
            $entity = is_array($change) ? ($change['entity'] ?? null) : null;
            $id = is_array($change) ? ($change['entityId'] ?? null) : null;

            if (is_string($entity) && in_array($entity, $wanted, true) && is_string($id) && $id !== '') {
                $ids[$entity][$id] = $id;
            }
        }

        return array_map('array_values', $ids);
    }
}
