<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Support\Portal\ShopPin;
use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Anomalies\Models\Anomaly;
use App\Domain\Anomalies\Queries\AnomalyList;
use App\Domain\Anomalies\Support\AnomalyVisibility;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Shared\Rules\ValidUlid;
use App\Domain\Tenancy\Enums\Ability;
use Illuminate\Validation\Rule;

/**
 * Read: unusual-activity findings (module 6.6) with their facts and usual figures, as the Unusual activity page shows
 * them to this user: staff-level findings (named till users) only for owners and managers, a one-shop user's shop
 * only. The findings are computed by deterministic checks; the model only reports them.
 */
final class ListAnomalies extends PortalReadTool
{
    public const LIMIT = 20;

    public function name(): string
    {
        return 'list_anomalies';
    }

    public function description(): string
    {
        return 'Unusual activity found by the automatic checks: staff voids, refunds, no-sales or manual discounts far '
            .'above their own or their team\'s normal, repeated cash shortfalls, hours with no sales, days well below '
            .'normal, price-override spikes, products going below zero, refunds without the original sale and sales '
            .'outside opening hours. Each has a severity, status (new, acknowledged, dismissed), the facts with the '
            .'usual figures, and a link. Newest first, at most 20.';
    }

    public function inputSchema(): array
    {
        return self::object([
            'status' => ['type' => 'string', 'enum' => ['open', 'new', 'acknowledged', 'dismissed', 'all'], 'description' => 'Default open (new or acknowledged).'],
            'severity' => ['type' => 'string', 'enum' => array_map(fn (AnomalySeverity $s) => $s->value, AnomalySeverity::cases()), 'description' => 'Optional. Only this severity or higher.'],
            'kind' => ['type' => 'string', 'enum' => array_map(fn (AnomalyKind $k) => $k->value, AnomalyKind::cases()), 'description' => 'Optional. One kind of finding.'],
            'shop_id' => ShopPin::schema(),
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'description' => 'Trading days back to look, default 30.'],
        ]);
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['open', 'new', 'acknowledged', 'dismissed', 'all'])],
            'severity' => ['nullable', Rule::enum(AnomalySeverity::class)],
            'kind' => ['nullable', Rule::enum(AnomalyKind::class)],
            'shop_id' => ['nullable', 'string', new ValidUlid],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }

    public function requiredAbility(): Ability
    {
        return Ability::ReportsView;
    }

    public function handle(array $input, AiContext $context): array
    {
        $shop = ShopPin::resolve($input['shop_id'] ?? null);
        $role = $context->currentRole();
        $status = (string) ($input['status'] ?? 'open');
        $to = TradingDay::today()->format('Y-m-d');
        $from = TradingDay::today()->subDays((int) ($input['days'] ?? 30) - 1)->format('Y-m-d');
        $min = isset($input['severity']) ? AnomalySeverity::from((string) $input['severity']) : null;

        $query = AnomalyVisibility::scope(Anomaly::query(), $role, $shop->pinned ? $shop->id : null)
            ->when($shop->id !== null, fn ($q) => $q->where('branch_id', $shop->id))
            ->whereBetween('trading_day', [$from, $to])
            ->when($min !== null, fn ($q) => $q->whereIn('severity', AnomalySeverity::atLeast($min ?? AnomalySeverity::Low)))
            ->when(isset($input['kind']), fn ($q) => $q->where('kind', (string) $input['kind']))
            ->when(match ($status) {
                'open' => [AnomalyStatus::New->value, AnomalyStatus::Acknowledged->value],
                'all' => null,
                default => [$status],
            }, fn ($q, array $statuses) => $q->whereIn('status', $statuses));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('detected_at')->limit(self::LIMIT)->get();

        $this->links->add('Unusual activity · '.$shop->name, '/app/anomalies', [
            'from' => $from, 'to' => $to, 'status' => $status, 'severity' => $input['severity'] ?? null, 'kind' => $input['kind'] ?? null,
            'shop' => $shop->pinned ? null : ($shop->id ?? 'all'),
        ], $shop);

        return [
            ...$shop->toArray(),
            'period' => ['from' => $from, 'to' => $to],
            'total' => $total,
            'staffFindingsHidden' => ! AnomalyVisibility::seesStaff($role) ?: null,
            'findings' => $rows->map(function (Anomaly $a) {
                $row = AnomalyList::row($a);

                return [
                    'id' => $row['id'], 'kind' => $row['kindLabel'], 'severity' => $row['severity'], 'status' => $row['status'],
                    'shop' => $row['shop'], 'staffMember' => $row['subjectName'], 'day' => $row['tradingDay'],
                    'title' => $row['title'], 'summary' => $row['summary'], 'facts' => $a->facts,
                    'statusReason' => $a->status_reason, 'link' => '/app/anomalies/'.$a->id,
                ];
            })->values()->all(),
        ];
    }
}
