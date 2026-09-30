<?php

namespace App\Domain\Compliance\Queries;

use App\Domain\Compliance\Data\ComplianceFilters;
use App\Domain\Compliance\Support\ComplianceLookup as L;
use App\Domain\Compliance\Support\Expiry;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\TrainingRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Staff training records (module 5.7): who was trained on what, when, by whom and when it runs out. Not limited by
 * the date filter (a record from years ago can still be in force); soonest expiry first. Read only: the shop owns
 * them. Expiring = within Expiry::TRAINING_SOON_DAYS.
 */
final class TrainingList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, ComplianceFilters $f): array
    {
        $table = TableQuery::from($request)->searchable(['topic', 'trainer_name'])
            ->sortable(['expires_on', 'trained_on', 'topic'])->defaultSort('expires_on', 'asc')->defaultPerPage(25);
        $query = Expiry::filter(self::base($f)->when($f->type !== null, fn (Builder $q) => $q->where('topic', $f->type)), $f->status, Expiry::TRAINING_SOON_DAYS);
        $page = $table->paginator(Expiry::nullsLast($query, $table->sort() ?? 'expires_on', $table->direction()));
        /** @var list<TrainingRecord> $rows */
        $rows = $page->items();
        $shops = L::shops(array_map(fn (TrainingRecord $r) => $r->branch_id, $rows));
        $staff = L::staff(array_map(fn (TrainingRecord $r) => $r->user_id, $rows));
        $count = fn (string $status) => Expiry::filter(self::base($f), $status, Expiry::TRAINING_SOON_DAYS)->count();

        return [
            'records' => [
                'data' => array_map(fn (TrainingRecord $r) => [
                    'id' => $r->id,
                    'staff' => L::name($staff, $r->user_id),
                    'topic' => L::blank($r->topic),
                    'trainedOn' => $r->trained_on->format('Y-m-d'),
                    'expiresOn' => $r->expires_on?->format('Y-m-d'),
                    'status' => Expiry::status($r->expires_on, Expiry::TRAINING_SOON_DAYS),
                    'daysLeft' => Expiry::daysLeft($r->expires_on),
                    'trainer' => L::blank($r->trainer_name),
                    'notes' => L::blank($r->notes),
                    'shop' => L::name($shops, $r->branch_id),
                ], $rows),
                'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => $table->search(), 'sort' => $table->sort(), 'direction' => $table->direction()],
            ],
            'summary' => [
                'total' => self::base($f)->count(),
                'staff' => self::base($f)->distinct()->count('user_id'),
                'expiring' => $count('expiring'),
                'expired' => $count('expired'),
            ],
            'topics' => self::base($f)->distinct()->orderBy('topic')->pluck('topic')->filter()->map(fn ($t) => ['value' => (string) $t, 'label' => (string) $t])->values()->all(),
            'soonDays' => Expiry::TRAINING_SOON_DAYS,
        ];
    }

    /** @return Builder<TrainingRecord> */
    public static function base(ComplianceFilters $f): Builder
    {
        return $f->scope(TrainingRecord::query())->when($f->staff !== null, fn (Builder $q) => $q->where('user_id', $f->staff));
    }
}
