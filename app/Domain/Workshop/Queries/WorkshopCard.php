<?php

namespace App\Domain\Workshop\Queries;

use App\Domain\Licensing\Enums\AddOn;
use App\Domain\Licensing\Support\BranchAddOns;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use App\Domain\Workshop\Enums\WorkshopOrderStatus;
use App\Domain\Workshop\Enums\WorkshopTokenKind;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopOrder;
use App\Domain\Workshop\Models\WorkshopToken;
use App\Domain\Workshop\Models\WorkshopWebhookDelivery;
use App\Domain\Workshop\Support\ConnectorSecret;
use App\Domain\Workshop\Support\ProductScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The "Workshop integration (BikeBook)" card of each shop (WORKSHOP-BIKEBOOK.md §8): licence tick, status (connected
 * since, failed with the reason, disconnected), last BikeBook call, API key (last 4 only) or OAuth, BikeBook's webhook
 * (no secret), and counts (products in scope, customers shared, open workshop orders, failed webhooks). The tenant
 * portal shows the same without keys and actions (`forTenant`).
 */
final class WorkshopCard
{
    /**
     * @param  Collection<int, Branch>  $branches
     * @return list<array<string, mixed>>
     */
    public static function forBranches(Company $company, Collection $branches): array
    {
        return app(CurrentCompany::class)->runAs($company, fn () => $branches->map(fn (Branch $branch) => self::forBranch($branch))->values()->all());
    }

    /**
     * Call inside the shop's company.
     *
     * @return array<string, mixed>
     */
    public static function forBranch(Branch $branch): array
    {
        $connection = WorkshopConnection::query()->where('branch_id', $branch->id)->first();
        $now = CarbonImmutable::now('UTC');
        $oauth = $connection !== null && WorkshopToken::query()->where('connection_id', $connection->id)->where('kind', WorkshopTokenKind::Refresh->value)
            ->whereNull('revoked_at')->where('expires_at', '>', $now)->exists();

        return [
            'branchId' => $branch->id,
            'branchName' => $branch->name,
            'branchActive' => $branch->is_active,
            'addOn' => BranchAddOns::has($branch, AddOn::WorkshopIntegration),
            'addOnLabel' => AddOn::WorkshopIntegration->label(),
            'connectionId' => $connection?->id,
            'status' => $connection?->status->value ?? 'notConnected',
            'statusLabel' => $connection?->status->label() ?? 'Not connected',
            'connectedAt' => ApiDate::format($connection?->connected_at),
            'disconnectedAt' => ApiDate::format($connection?->disconnected_at),
            'lastCallAt' => ApiDate::format($connection?->last_pulled_at),
            'lastError' => $connection?->last_error,
            'apiKey' => $connection?->api_key_last4 === null ? null : [
                'masked' => ConnectorSecret::mask($connection->api_key_last4),
                'createdAt' => ApiDate::format($connection->api_key_created_at),
            ],
            'oauth' => $oauth,
            'webhook' => $connection === null || $connection->webhook_url === null ? null : [
                'url' => $connection->webhook_url,
                'subscriptions' => $connection->webhook_subscriptions ?? [],
                'enabled' => $connection->webhook_enabled,
            ],
            'counts' => $connection === null ? null : self::counts($connection),
        ];
    }

    /**
     * The tenant's read-only card: status and counts, no keys, no webhook URL, no errors' details.
     *
     * @return array<string, mixed>
     */
    public static function forTenant(Branch $branch): array
    {
        $card = self::forBranch($branch);

        return [
            'addOn' => $card['addOn'],
            'status' => $card['status'],
            'statusLabel' => $card['statusLabel'],
            'connectedAt' => $card['connectedAt'],
            'lastCallAt' => $card['lastCallAt'],
            'failing' => $card['lastError'] !== null,
            'counts' => $card['counts'],
        ];
    }

    /**
     * @return array{productsInScope: int, customers: int, openOrders: int, failedWebhooks: int}
     */
    private static function counts(WorkshopConnection $connection): array
    {
        $scope = ProductScope::for($connection);

        return [
            'productsInScope' => $scope->apply(DB::table('products')->where('company_id', $connection->company_id)->whereNull('parent_product_id'))->count(),
            'customers' => Customer::query()->where('is_active', true)->whereNull('anonymised_at')->count(),
            'openOrders' => WorkshopOrder::query()->where('connection_id', $connection->id)->where('status', WorkshopOrderStatus::Open->value)->whereNull('locked_at')->count(),
            'failedWebhooks' => WorkshopWebhookDelivery::query()->where('connection_id', $connection->id)->whereNotNull('failed_at')->count(),
        ];
    }
}
