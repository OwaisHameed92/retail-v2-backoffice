<?php

namespace App\Domain\Leads\Queries;

use App\Domain\Leads\Data\LeadFilters;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\PhoneDigits;
use App\Domain\Mail\Support\MailFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin lead list and board query: filters, search and the "today" boundaries in Europe/London.
 */
final class LeadQuery
{
    /**
     * @param  bool  $withStatus  false for the board, which groups by status itself.
     * @return Builder<Lead>
     */
    public static function filtered(LeadFilters $filters, ?string $search, ?string $adminId, bool $withStatus = true): Builder
    {
        $query = Lead::query();

        if ($filters->status === 'archived') {
            $query->onlyTrashed();
        } elseif ($withStatus && $filters->status === 'open') {
            $query->open();
        } elseif ($withStatus && $filters->status !== null) {
            $query->where('status', $filters->status);
        }

        if ($filters->source !== null) {
            $query->where('source', $filters->source->value);
        }

        match ($filters->assigned) {
            null => null,
            'me' => $query->where('assigned_admin_id', $adminId ?? ''),
            'none' => $query->whereNull('assigned_admin_id'),
            default => $query->where('assigned_admin_id', $filters->assigned),
        };

        $now = CarbonImmutable::now();

        match ($filters->followUp) {
            null => null,
            'due' => $query->whereNotNull('follow_up_at')->where('follow_up_at', '<=', self::endOfToday($now)),
            'overdue' => $query->whereNotNull('follow_up_at')->where('follow_up_at', '<', $now),
            'week' => $query->whereBetween('follow_up_at', [$now, self::endOfToday($now)->addDays(7)]),
            'none' => $query->whereNull('follow_up_at'),
            default => null,
        };

        if ($search !== null) {
            self::search($query, $search);
        }

        return $query;
    }

    /**
     * @param  Builder<Lead>  $query
     */
    public static function search(Builder $query, string $search): void
    {
        $like = '%'.$search.'%';
        $digits = PhoneDigits::from($search);

        $query->where(function (Builder $q) use ($like, $digits) {
            $q->where('business_name', 'like', $like)
                ->orWhere('contact_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('town', 'like', $like)
                ->orWhere('postcode', 'like', $like);

            if ($digits !== null) {
                $q->orWhere('phone_digits', 'like', '%'.$digits.'%');
            }
        });
    }

    /** 23:59:59 today in Europe/London, as UTC. */
    public static function endOfToday(CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone(MailFormat::TIMEZONE)->endOfDay()->utc();
    }

    /**
     * Open leads per status, and all leads per status, for the filter counts.
     *
     * @return array<string, int>
     */
    public static function statusCounts(): array
    {
        $counts = Lead::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)->all();

        $counts['open'] = ($counts[LeadStatus::New->value] ?? 0) + ($counts[LeadStatus::Contacted->value] ?? 0);
        $counts['archived'] = Lead::onlyTrashed()->count();

        return $counts;
    }
}
