<?php

namespace App\Domain\Anomalies\Data;

use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The Unusual activity page filters (module 6.6), read leniently from the query string (a bad value is dropped):
 * `status` (open = new or acknowledged, the default; new, acknowledged, dismissed, all), `severity`, `kind`,
 * `shop` (an id, `all`, or absent = the top-bar shop; a one-shop user always gets theirs), `from` / `to` (London
 * trading days, default the last 30 days).
 */
final readonly class AnomalyFilters
{
    public const STATUSES = ['open', 'new', 'acknowledged', 'dismissed', 'all'];

    public const DEFAULT_DAYS = 30;

    public function __construct(
        public string $from,
        public string $to,
        public string $status = 'open',
        public ?string $severity = null,
        public ?string $kind = null,
        public ?string $shop = null,
        public bool $shopLocked = false,
    ) {}

    public static function fromRequest(Request $request, CurrentCompany $tenancy, ?string $currentShop): self
    {
        $today = TradingDay::today();
        $from = self::day($request->query('from')) ?? $today->subDays(self::DEFAULT_DAYS - 1)->format('Y-m-d');
        $to = self::day($request->query('to')) ?? $today->format('Y-m-d');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $restricted = $tenancy->restrictedBranchId();
        $shop = $request->query('shop');
        $status = $request->query('status');
        $severity = $request->query('severity');
        $kind = $request->query('kind');

        return new self(
            from: $from,
            to: $to,
            status: in_array($status, self::STATUSES, true) ? $status : 'open',
            severity: is_string($severity) && AnomalySeverity::tryFrom($severity) !== null ? $severity : null,
            kind: is_string($kind) && AnomalyKind::tryFrom($kind) !== null ? $kind : null,
            shop: match (true) {
                $restricted !== null => $restricted,
                $shop === 'all' => null,
                is_string($shop) && preg_match('/^[0-9A-Za-z]{26}$/', $shop) === 1 => $shop,
                default => $currentShop,
            },
            shopLocked: $restricted !== null,
        );
    }

    /**
     * @return array{from: string, to: string, status: string, severity: string|null, kind: string|null, shop: string|null, shopLocked: bool}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from, 'to' => $this->to, 'status' => $this->status, 'severity' => $this->severity,
            'kind' => $this->kind, 'shop' => $this->shop, 'shopLocked' => $this->shopLocked,
        ];
    }

    private static function day(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC')?->format('Y-m-d') === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
