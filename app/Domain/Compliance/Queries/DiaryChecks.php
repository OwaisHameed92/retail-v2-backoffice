<?php

namespace App\Domain\Compliance\Queries;

use App\Domain\Compliance\Data\ComplianceFilters;
use App\Domain\Compliance\Support\ComplianceLookup as L;
use App\Domain\Compliance\Support\MissedChecks;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\DiaryCheckDefinition;
use App\Domain\TillData\Models\DiaryCheckRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Diary checks (module 5.7): the shops' check definitions (fridge temperatures, fire exits, till roll…) with how many
 * were due, done and missed per their schedule in the chosen days (MissedChecks), the missed ones listed, and the
 * records log. Definitions are the shop's (ownership.json: branch), so the portal only reads them.
 */
final class DiaryChecks
{
    private const MISSED_LIMIT = 100;

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, ComplianceFilters $f, CarbonImmutable $now): array
    {
        $definitions = $f->scope(DiaryCheckDefinition::query())->orderBy('branch_id')->orderBy('sort_order')->orderBy('name')->get();
        $evaluated = MissedChecks::forDefinitions($definitions, $f->from, $f->to, $now);
        $shops = L::shops($definitions->pluck('branch_id'));
        $last = DiaryCheckRecord::query()->whereIn('diary_check_definition_id', $definitions->pluck('id')->all())
            ->groupBy('diary_check_definition_id')->selectRaw('diary_check_definition_id as d, max(recorded_at) as at')->toBase()->pluck('at', 'd');

        $rows = $definitions->map(function (DiaryCheckDefinition $d) use ($evaluated, $shops, $last) {
            $periods = collect($evaluated[$d->id] ?? []);

            return [
                'id' => $d->id,
                'name' => L::blank($d->name) ?? 'Unnamed check',
                'category' => L::blank($d->category),
                'schedule' => $d->schedule?->value,
                'active' => $d->is_active,
                'shop' => L::name($shops, $d->branch_id),
                'due' => $periods->count(),
                'done' => $periods->where('state', 'done')->count(),
                'missed' => $periods->where('state', 'missed')->count(),
                'dueNow' => $periods->where('state', 'due')->count() > 0,
                'lastDoneAt' => L::iso($last[$d->id] ?? null),
            ];
        })->values();

        $missed = $definitions->flatMap(fn (DiaryCheckDefinition $d) => collect($evaluated[$d->id] ?? [])->where('state', 'missed')
            ->map(fn (array $p) => ['definitionId' => $d->id, 'name' => L::blank($d->name) ?? 'Unnamed check', 'shop' => L::name($shops, $d->branch_id), 'schedule' => $d->schedule?->value, 'period' => $p['label'], 'start' => L::iso($p['start']), 'end' => L::iso($p['end'])]))
            ->sortByDesc('start')->values();

        return [
            'definitions' => $rows->all(),
            'missed' => $missed->take(self::MISSED_LIMIT)->all(),
            'summary' => [
                'definitions' => $rows->where('active', true)->count(),
                'due' => (int) $rows->sum('due'),
                'done' => (int) $rows->sum('done'),
                'missed' => $missed->count(),
                'failed' => self::records($f)->where('passed', false)->count(),
            ],
            'records' => self::log($request, $f, $definitions->pluck('name', 'id')->all()),
        ];
    }

    /**
     * @param  array<string, string>  $names  definition id → name
     * @return array<string, mixed>
     */
    private static function log(Request $request, ComplianceFilters $f, array $names): array
    {
        $table = TableQuery::from($request)->sortable(['recorded_at'])->defaultSort('recorded_at', 'desc')->defaultPerPage(25);
        $page = $table->paginator(self::records($f)->when($f->type !== null, fn (Builder $q) => $q->where('diary_check_definition_id', $f->type)));
        /** @var list<DiaryCheckRecord> $rows */
        $rows = $page->items();
        $shops = L::shops(array_map(fn (DiaryCheckRecord $r) => $r->branch_id, $rows));
        $staff = L::staff(array_map(fn (DiaryCheckRecord $r) => $r->recorded_by_user_id, $rows));

        return [
            'data' => array_map(fn (DiaryCheckRecord $r) => [
                'id' => $r->id,
                'recordedAt' => L::iso($r->recorded_at),
                'check' => $names[(string) $r->diary_check_definition_id] ?? null,
                'shop' => L::name($shops, $r->branch_id),
                'staff' => L::name($staff, $r->recorded_by_user_id),
                'value' => L::blank($r->value),
                'passed' => $r->passed,
                'note' => L::blank($r->note),
            ], $rows),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => null, 'sort' => $table->sort(), 'direction' => $table->direction()],
        ];
    }

    /** @return Builder<DiaryCheckRecord> */
    private static function records(ComplianceFilters $f): Builder
    {
        return $f->during($f->scope(DiaryCheckRecord::query()), 'recorded_at')
            ->when($f->staff !== null, fn (Builder $q) => $q->where('recorded_by_user_id', $f->staff));
    }
}
