<?php

namespace App\Domain\Transfers\Queries;

use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Transfers\Data\TransferFilters;
use App\Domain\Transfers\Support\TransferFigures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Transfer discrepancies across shops (module 5.3): for transfers received in a period (London days, by the receiving
 * shop's receipt, default the last 90 days), what was sent against what arrived, per route (from → to) and line by
 * line, valued at cost (TransferFigures). A one-shop user sees only transfers from or to their shop.
 */
final class DiscrepancyReport
{
    public const DEFAULT_DAYS = 90;

    /** Discrepancy lines shown on screen (the CSV has all of them). */
    public const SCREEN_LINES = 200;

    public static function filters(Request $request): TransferFilters
    {
        $today = TradingDay::today();

        return TransferFilters::from($request, [$today->subDays(self::DEFAULT_DAYS - 1)->format('Y-m-d'), $today->format('Y-m-d')]);
    }

    /** @return array<string, mixed> */
    public static function for(TransferFilters $filters): array
    {
        $names = TransferList::shopNames();
        $routes = [];
        $lines = [];
        $totals = ['transfers' => [], 'discrepant' => [], 'short' => '0.0000', 'over' => '0.0000', 'sentValue' => '0.00', 'varianceValue' => '0.00'];
        $count = 0;

        foreach (self::query($filters)->cursor() as $row) {
            $f = TransferFigures::line($row->qty_dispatched, $row->qty_received, $row->unit_cost);
            $key = $row->from_branch_id.'>'.$row->to_branch_id;
            $route = $routes[$key] ?? [
                'from' => $names[$row->from_branch_id] ?? 'Unknown shop', 'to' => $names[$row->to_branch_id] ?? 'Unknown shop',
                'transfers' => [], 'discrepant' => [], 'short' => '0.0000', 'over' => '0.0000', 'sentValue' => '0.00', 'varianceValue' => '0.00',
            ];

            self::add($route, (string) $row->transfer_id, $f);
            self::add($totals, (string) $row->transfer_id, $f);
            $routes[$key] = $route;

            if (! Money::isZero($f['variance']) && $count++ < self::SCREEN_LINES) {
                $lines[] = [...self::lineRow($row, $f, $names), 'productId' => $row->product_id];
            }
        }

        $products = PurchasingNames::for(array_map(fn (array $l) => (object) ['product_id' => $l['productId']], $lines));

        return [
            'filters' => $filters->toArray(),
            'summary' => self::bucket($totals),
            'routes' => array_values(array_map(fn (array $r) => ['from' => $r['from'], 'to' => $r['to'], ...self::bucket($r)], self::worstFirst($routes))),
            'lines' => array_map(function (array $l) use ($products) {
                $l['product'] = $products->product($l['productId'], $l['productName']);
                unset($l['productId'], $l['productName']);

                return $l;
            }, $lines),
            'lineCount' => $count,
            'truncated' => $count > self::SCREEN_LINES,
            ...TransferList::shared(),
        ];
    }

    /**
     * Every discrepancy line, for the CSV.
     *
     * @return iterable<array<string, mixed>>
     */
    public static function csvLines(TransferFilters $filters): iterable
    {
        $names = TransferList::shopNames();

        foreach (self::query($filters)->cursor() as $row) {
            $f = TransferFigures::line($row->qty_dispatched, $row->qty_received, $row->unit_cost);

            if (! Money::isZero($f['variance'])) {
                yield self::lineRow($row, $f, $names);
            }
        }
    }

    /** Receipt lines of transfers received in the period, with their transfer's route, oldest receipt last. */
    private static function query(TransferFilters $filters): Builder
    {
        $companyId = (string) app(CurrentCompany::class)->id();
        [$from, $to] = $filters->window();
        $query = DB::table('stock_transfer_receipt_lines as rl')
            ->join('stock_transfer_receipts as r', fn ($j) => $j->on('r.id', '=', 'rl.receipt_id')->on('r.company_id', '=', 'rl.company_id'))
            ->join('stock_transfers as t', fn ($j) => $j->on('t.id', '=', 'rl.transfer_id')->on('t.company_id', '=', 'rl.company_id'))
            ->leftJoin('stock_transfer_lines as tl', fn ($j) => $j->on('tl.id', '=', 'rl.transfer_line_id')->on('tl.company_id', '=', 'rl.company_id'))
            ->where('rl.company_id', $companyId)
            ->whereNull('rl.deleted_at')->whereNull('r.deleted_at')->whereNull('t.deleted_at')
            ->when($from !== null, fn (Builder $q) => $q->where('r.received_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('r.received_at', '<', $to))
            ->select(['rl.id', 'rl.transfer_id', 'rl.product_id', 'rl.qty_dispatched', 'rl.qty_received', 'rl.unit_cost', 'r.received_at',
                't.reference', 't.from_branch_id', 't.to_branch_id', 'tl.product_name'])
            ->orderByDesc('r.received_at')->orderBy('t.reference')->orderBy('rl.id');

        if ($filters->shop !== null) {
            $shop = $filters->shop;
            match ($filters->flow) {
                'out' => $query->where('t.from_branch_id', $shop),
                'in' => $query->where('t.to_branch_id', $shop),
                default => $query->where(fn (Builder $q) => $q->where('t.from_branch_id', $shop)->orWhere('t.to_branch_id', $shop)),
            };
        }

        return $query;
    }

    /**
     * @param  array{sent: string, received: string, variance: string, sentValue: string, receivedValue: string, varianceValue: string}  $f
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private static function lineRow(object $row, array $f, array $names): array
    {
        return [
            'id' => (string) $row->id,
            'transferId' => (string) $row->transfer_id,
            'reference' => (string) $row->reference,
            'from' => $names[$row->from_branch_id] ?? 'Unknown shop',
            'to' => $names[$row->to_branch_id] ?? 'Unknown shop',
            'receivedAt' => is_string($row->received_at) && $row->received_at !== '' ? CarbonImmutable::parse($row->received_at, 'UTC')->toIso8601ZuluString() : null,
            'productName' => $row->product_name ?: null,
            'unitCost' => Money::normalise($row->unit_cost ?? 0, Money::QUANTITY_SCALE),
            ...$f,
        ];
    }

    /**
     * Adds one receipt line to a total (the whole report or one route).
     *
     * @param  array<string, mixed>  $bucket
     * @param  array{sent: string, received: string, variance: string, sentValue: string, receivedValue: string, varianceValue: string}  $f
     */
    private static function add(array &$bucket, string $transferId, array $f): void
    {
        $bucket['transfers'][$transferId] = true;
        $bucket['sentValue'] = Money::add($bucket['sentValue'], $f['sentValue']);
        $bucket['varianceValue'] = Money::add($bucket['varianceValue'], $f['varianceValue']);

        if (! Money::isZero($f['variance'])) {
            $bucket['discrepant'][$transferId] = true;
            $side = Money::isNegative($f['variance']) ? 'short' : 'over';
            $bucket[$side] = Money::add($bucket[$side], ltrim($f['variance'], '-'), Money::QUANTITY_SCALE);
        }
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array{transfers: int, discrepant: int, short: string, over: string, sentValue: string, varianceValue: string}
     */
    private static function bucket(array $b): array
    {
        return [...$b, 'transfers' => count($b['transfers']), 'discrepant' => count($b['discrepant'])];
    }

    /**
     * @param  array<string, array<string, mixed>>  $routes
     * @return array<string, array<string, mixed>>
     */
    private static function worstFirst(array $routes): array
    {
        uasort($routes, fn (array $a, array $b) => Money::compare($a['varianceValue'], $b['varianceValue']) ?: strcmp($a['from'].$a['to'], $b['from'].$b['to']));

        return $routes;
    }
}
