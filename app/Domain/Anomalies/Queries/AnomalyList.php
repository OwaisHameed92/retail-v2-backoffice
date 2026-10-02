<?php

namespace App\Domain\Anomalies\Queries;

use App\Domain\Anomalies\Data\AnomalyFilters;
use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Anomalies\Models\Anomaly;
use App\Domain\Anomalies\Support\AnomalyVisibility;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Props of the Unusual activity page (module 6.6): the findings the user may see (AnomalyVisibility) with the
 * filters, newest first, counts for the stat cards, and the filter options. Runs in the company scope.
 */
final class AnomalyList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, AnomalyFilters $f, ?CompanyRole $role, ?string $restricted): array
    {
        $seesStaff = AnomalyVisibility::seesStaff($role);
        $shops = Branch::query()->when($f->shopLocked, fn ($q) => $q->whereKey($f->shop))->orderBy('name')->pluck('name', 'id')
            ->map(fn ($n) => (string) $n)->all();
        $table = TableQuery::from($request)->sortable(['detected_at', 'trading_day', 'score'])->defaultSort('detected_at', 'desc')->defaultPerPage(25);

        $rows = $table->paginate(self::filtered($f, $role, $restricted), fn (Anomaly $a) => self::row($a, $shops));
        $counts = self::visible($f, $role, $restricted)->whereBetween('trading_day', [$f->from, $f->to])
            ->groupBy('status', 'severity')->select(['status', 'severity', DB::raw('COUNT(*) as n')])->toBase()->get();
        $count = fn (callable $match) => (int) $counts->filter(fn ($r) => $match((string) $r->status, (string) $r->severity))->sum('n');

        return [
            'anomalies' => $rows,
            'summary' => [
                'new' => $count(fn ($s) => $s === AnomalyStatus::New->value),
                'acknowledged' => $count(fn ($s) => $s === AnomalyStatus::Acknowledged->value),
                'dismissed' => $count(fn ($s) => $s === AnomalyStatus::Dismissed->value),
                'highOpen' => $count(fn ($s, $v) => $s !== AnomalyStatus::Dismissed->value && $v === AnomalySeverity::High->value),
            ],
            'filters' => $f->toArray(),
            'options' => [
                'shops' => array_map(fn ($id, $name) => ['value' => (string) $id, 'label' => $name], array_keys($shops), $shops),
                'kinds' => AnomalyKind::options($seesStaff),
                'severities' => array_map(fn (AnomalySeverity $s) => ['value' => $s->value, 'label' => $s->label()], array_reverse(AnomalySeverity::cases())),
            ],
            'seesStaff' => $seesStaff,
            'canManage' => AnomalyVisibility::canManage($role),
        ];
    }

    /**
     * @param  array<string, string>  $shops
     * @return array<string, mixed>
     */
    public static function row(Anomaly $a, array $shops = []): array
    {
        return [
            'id' => $a->id,
            'kind' => $a->kind->value,
            'kindLabel' => $a->kind->label(),
            'severity' => $a->severity->value,
            'status' => $a->status->value,
            'title' => $a->title,
            'summary' => $a->summary,
            'shop' => $shops[(string) $a->branch_id] ?? Branch::query()->withTrashed()->whereKey($a->branch_id)->value('name') ?? 'Unknown shop',
            'staffLevel' => $a->kind->staffLevel(),
            'subjectName' => $a->subject_name,
            'tradingDay' => substr($a->trading_day, 0, 10),
            'detectedAt' => $a->detected_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'occurrences' => $a->occurrences,
        ];
    }

    /**
     * @return Builder<Anomaly>
     */
    private static function visible(AnomalyFilters $f, ?CompanyRole $role, ?string $restricted): Builder
    {
        return AnomalyVisibility::scope(Anomaly::query(), $role, $restricted)
            ->when($f->shop !== null, fn (Builder $q) => $q->where('branch_id', $f->shop));
    }

    /**
     * @return Builder<Anomaly>
     */
    private static function filtered(AnomalyFilters $f, ?CompanyRole $role, ?string $restricted): Builder
    {
        return self::visible($f, $role, $restricted)
            ->whereBetween('trading_day', [$f->from, $f->to])
            ->when($f->severity !== null, fn (Builder $q) => $q->where('severity', $f->severity))
            ->when($f->kind !== null, fn (Builder $q) => $q->where('kind', $f->kind))
            ->when(match ($f->status) {
                'open' => [AnomalyStatus::New->value, AnomalyStatus::Acknowledged->value],
                'all' => null,
                default => [$f->status],
            }, fn (Builder $q, array $statuses) => $q->whereIn('status', $statuses));
    }
}
