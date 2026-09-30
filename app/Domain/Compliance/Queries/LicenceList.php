<?php

namespace App\Domain\Compliance\Queries;

use App\Domain\Compliance\Data\ComplianceFilters;
use App\Domain\Compliance\Support\ComplianceLookup as L;
use App\Domain\Compliance\Support\Expiry;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\ComplianceLicence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Licences the shops hold (module 5.7): premises, personal, alcohol, tobacco, lottery… (`ComplianceLicence`), with
 * their number, holder and expiry, soonest expiry first. Read only: the shop owns them. Expiring = within
 * Expiry::LICENCE_SOON_DAYS.
 */
final class LicenceList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, ComplianceFilters $f): array
    {
        $table = TableQuery::from($request)->searchable(['licence_type', 'number', 'holder_name'])
            ->sortable(['expires_on', 'licence_type'])->defaultSort('expires_on', 'asc')->defaultPerPage(25);
        $query = Expiry::filter($f->scope(ComplianceLicence::query())->when($f->type !== null, fn (Builder $q) => $q->where('licence_type', $f->type)), $f->status, Expiry::LICENCE_SOON_DAYS);
        $page = $table->paginator(Expiry::nullsLast($query, $table->sort() ?? 'expires_on', $table->direction()));
        /** @var list<ComplianceLicence> $rows */
        $rows = $page->items();
        $shops = L::shops(array_map(fn (ComplianceLicence $r) => $r->branch_id, $rows));
        $count = fn (string $status) => Expiry::filter($f->scope(ComplianceLicence::query()), $status, Expiry::LICENCE_SOON_DAYS)->count();

        return [
            'licences' => [
                'data' => array_map(fn (ComplianceLicence $r) => [
                    'id' => $r->id,
                    'type' => L::blank($r->licence_type),
                    'number' => L::blank($r->number),
                    'holder' => L::blank($r->holder_name),
                    'issuedOn' => $r->issued_on->format('Y-m-d'),
                    'expiresOn' => $r->expires_on?->format('Y-m-d'),
                    'status' => Expiry::status($r->expires_on, Expiry::LICENCE_SOON_DAYS),
                    'daysLeft' => Expiry::daysLeft($r->expires_on),
                    'notes' => L::blank($r->notes),
                    'shop' => L::name($shops, $r->branch_id),
                ], $rows),
                'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => $table->search(), 'sort' => $table->sort(), 'direction' => $table->direction()],
            ],
            'summary' => [
                'total' => $f->scope(ComplianceLicence::query())->count(),
                'expiring' => $count('expiring'),
                'expired' => $count('expired'),
            ],
            'types' => $f->scope(ComplianceLicence::query())->distinct()->orderBy('licence_type')->pluck('licence_type')->filter()
                ->map(fn ($t) => ['value' => (string) $t, 'label' => (string) $t])->values()->all(),
            'soonDays' => Expiry::LICENCE_SOON_DAYS,
        ];
    }
}
