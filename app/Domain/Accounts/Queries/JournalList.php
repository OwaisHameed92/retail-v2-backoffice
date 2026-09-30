<?php

namespace App\Domain\Accounts\Queries;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Support\AccountChart;
use App\Domain\Accounts\Support\RefundFix;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\JournalEntry;
use App\Domain\TillData\Models\JournalLine;
use App\Domain\TillData\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The tills' journals (module 5.5), read only: entries by date, shop, type and account, and one entry with its
 * lines. Old refund entries (before the till's 0.1.15 fix) are flagged "posted before the refund fix".
 */
final class JournalList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, AccountsFilters $filters): array
    {
        $table = TableQuery::from($request)->searchable(['journal_entries.memo', 'journal_entries.ref_id'])
            ->sortable(['date', 'posted_at', 'total_debits'])->defaultSort('date', 'desc')->defaultPerPage(25);
        $query = JournalEntry::query()
            ->where('date', '>=', $filters->from)->where('date', '<=', $filters->to)
            ->when($filters->shop !== null, fn (Builder $q) => $q->where('branch_id', $filters->shop))
            ->when($filters->refType !== null, fn (Builder $q) => $q->where('ref_type', $filters->refType))
            ->when($filters->account !== null, fn (Builder $q) => $q->whereIn('id', JournalLine::query()->select('journal_entry_id')->where('account_code', $filters->account)));
        $page = $table->paginator($query);
        /** @var list<JournalEntry> $entries */
        $entries = $page->items();
        $ids = array_map(fn (JournalEntry $e) => $e->id, $entries);
        $flagged = RefundFix::among($filters, $ids);
        $shops = CashLookup::shops(array_map(fn (JournalEntry $e) => $e->branch_id, $entries));
        $tills = CashLookup::tills(array_map(fn (JournalEntry $e) => $e->register_id, $entries));

        return [
            'entries' => [
                'data' => array_map(fn (JournalEntry $e) => [
                    ...self::row($e, $shops, $tills),
                    'oldRefund' => isset($flagged[$e->id]),
                ], $entries),
                'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => $table->search(), 'sort' => $table->sort(), 'direction' => $table->direction()],
            ],
            'refTypes' => JournalEntry::query()->whereNotNull('ref_type')->where('ref_type', '!=', '')->distinct()->orderBy('ref_type')->limit(60)->pluck('ref_type')
                ->map(fn ($t) => ['value' => (string) $t, 'label' => (string) $t])->values()->all(),
            'accounts' => array_values(array_map(fn (array $a) => ['value' => $a['code'], 'label' => $a['code'].' · '.$a['name']], AccountChart::byCode($filters->shop))),
            'refundFix' => RefundFix::summary($filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function show(JournalEntry $entry, AccountsFilters $filters): array
    {
        $chart = AccountChart::byCode();
        $lines = JournalLine::query()->where('journal_entry_id', $entry->id)->orderBy('id')->get();
        $shops = CashLookup::shops([$entry->branch_id]);
        $tills = CashLookup::tills([$entry->register_id]);
        $refId = (string) $entry->ref_id;
        $sale = $refId !== '' && Sale::query()->whereKey($refId)->when($filters->shopLocked, fn (Builder $q) => $q->where('branch_id', $filters->shop))->exists();

        return [
            'entry' => [
                ...self::row($entry, $shops, $tills),
                'oldRefund' => isset(RefundFix::among($filters->between('0001-01-01', '9999-12-31'), [$entry->id])[$entry->id]),
                'postedBy' => CashLookup::name(CashLookup::staff([$entry->posted_by_user_id]), $entry->posted_by_user_id),
                'reversesEntryId' => self::known($entry->reverses_entry_id),
                'reversedByEntryId' => self::known($entry->reversed_by_entry_id),
                'saleId' => $sale ? $refId : null,
                'lines' => $lines->map(fn (JournalLine $l) => [
                    'id' => $l->id,
                    'code' => (string) $l->account_code,
                    'name' => AccountChart::entry($chart, (string) $l->account_code)['name'],
                    'debit' => CashLookup::money($l->debit),
                    'credit' => CashLookup::money($l->credit),
                    'memo' => (string) $l->memo !== '' ? (string) $l->memo : null,
                ])->values()->all(),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $shops
     * @param  array<string, string>  $tills
     * @return array<string, mixed>
     */
    private static function row(JournalEntry $e, array $shops, array $tills): array
    {
        return [
            'id' => $e->id,
            'date' => $e->date->format('Y-m-d'),
            'refType' => (string) $e->ref_type !== '' ? (string) $e->ref_type : null,
            'refId' => (string) $e->ref_id !== '' ? (string) $e->ref_id : null,
            'memo' => (string) $e->memo !== '' ? (string) $e->memo : null,
            'shop' => CashLookup::name($shops, $e->branch_id),
            'till' => CashLookup::name($tills, $e->register_id),
            'postedAt' => CashLookup::iso($e->posted_at),
            'debits' => CashLookup::money($e->total_debits),
            'credits' => CashLookup::money($e->total_credits),
            'isReversed' => (bool) $e->is_reversed,
            'isReversal' => $e->reverses_entry_id !== null && $e->reverses_entry_id !== '',
        ];
    }

    /** A linked entry's id when the portal has it (in the user's reach). */
    private static function known(?string $id): ?string
    {
        return $id !== null && $id !== '' && JournalEntry::query()->whereKey($id)->exists() ? $id : null;
    }
}
