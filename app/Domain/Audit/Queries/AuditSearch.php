<?php

namespace App\Domain\Audit\Queries;

use App\Domain\Admin\Models\Admin;
use App\Domain\Audit\Data\AuditFilters;
use App\Domain\Shared\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The filtered audit log, newest first, paged by id (ULIDs sort by time) without counting: fast on millions of rows.
 * `$companyId` pins a tenant screen to its own business; null is the admin view across every business.
 */
final class AuditSearch
{
    /**
     * @return Builder<AuditLog>
     */
    public static function query(AuditFilters $f, ?string $companyId): Builder
    {
        $query = AuditLog::query();

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        } elseif ($f->company !== null) {
            $query->where('company_id', $f->company);
        }

        if ($f->actor !== null) {
            self::actor($query, $f->actor);
        }

        if ($f->action !== null) {
            str_ends_with($f->action, '.*')
                ? $query->where('action', 'like', self::escape(substr($f->action, 0, -1)).'%')
                : $query->where('action', $f->action);
        }

        $query->when($f->subjectType !== null, fn (Builder $q) => $q->where('subject_type', $f->subjectType))
            ->when($f->subjectId !== null, fn (Builder $q) => $q->where('subject_id', $f->subjectId))
            ->when($f->fromUtc() !== null, fn (Builder $q) => $q->where('created_at', '>=', $f->fromUtc()))
            ->when($f->toUtcExclusive() !== null, fn (Builder $q) => $q->where('created_at', '<', $f->toUtcExclusive()));

        if ($f->search !== null) {
            $term = $f->search;
            $query->where(fn (Builder $q) => $q->where('action', 'like', '%'.self::escape($term).'%')
                ->orWhere('subject_id', $term)
                ->orWhere('actor_id', $term)
                ->orWhere('ip', $term));
        }

        return $query;
    }

    /**
     * One page before (`$before`: newer) or after (`$after`: older) a cursor. Ordered by time, then id (ids alone
     * are not in time order for entries written with a backdated time or by other systems).
     *
     * @param  Builder<AuditLog>  $query
     * @return array{rows: Collection<int, AuditLog>, older: string|null, newer: string|null}
     */
    public static function page(Builder $query, ?string $after, ?string $before, int $perPage): array
    {
        $cursor = self::decode($before) ?? self::decode($after);
        $backwards = self::decode($before) !== null;
        $direction = $backwards ? 'asc' : 'desc';
        $op = $backwards ? '>' : '<';

        $rows = $query->clone()
            ->when($cursor !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('created_at', $op, $cursor[0])
                ->orWhere(fn (Builder $same) => $same->where('created_at', $cursor[0])->where('id', $op, $cursor[1]))))
            ->orderBy('created_at', $direction)->orderBy('id', $direction)
            ->limit($perPage + 1)
            ->get();

        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage);

        if ($backwards) {
            $rows = $rows->reverse()->values();
        }

        return [
            'rows' => $rows,
            'older' => $rows->isNotEmpty() && ($backwards || $more) ? self::encode($rows->last()) : null,
            'newer' => $rows->isNotEmpty() && ($backwards ? $more : $cursor !== null) ? self::encode($rows->first()) : null,
        ];
    }

    /** "20260901120000.<id>": the entry's time (UTC, to the second) and id. */
    public static function encode(AuditLog $log): string
    {
        return ($log->created_at?->copy()->utc()->format('YmdHis') ?? '00000000000000').'.'.$log->id;
    }

    /**
     * @return array{0: string, 1: string}|null [created_at as stored, id]
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null || preg_match('/^(\d{14})\.([0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26})$/', $cursor, $m) !== 1) {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat('!YmdHis', $m[1], new \DateTimeZone('UTC'));

        return $time === false ? null : [$time->format('Y-m-d H:i:s'), $m[2]];
    }

    /**
     * @param  Builder<AuditLog>  $query
     */
    private static function actor(Builder $query, string $actor): void
    {
        if ($actor === 'system') {
            $query->whereNull('actor_type');

            return;
        }

        if ($actor === 'staff') {
            $query->where('actor_type', (new Admin)->getMorphClass());

            return;
        }

        [$type, $id] = explode(':', $actor, 2);
        $query->where('actor_type', $type === 'admin' ? (new Admin)->getMorphClass() : (new User)->getMorphClass())->where('actor_id', $id);
    }

    /** Drops LIKE wildcards the person typed ("_" stays: it can only widen a match by one character). */
    private static function escape(string $value): string
    {
        return str_replace(['%', '\\'], '', $value);
    }
}
