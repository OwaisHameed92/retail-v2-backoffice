<?php

namespace App\Domain\Customers\Support;

use App\Domain\TillData\Enums\ConsentChannel;
use App\Domain\TillData\Models\Consent;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A customer's marketing consent per channel (UK GDPR / PECR), from the tills' `Consent` rows (branch-owned: the
 * customer gives or withdraws it at a till, online or on a paper form; the portal only reads it).
 *
 * The current state of a channel is its latest row by `at` (then `id`), soft-deleted rows left out: consent is given
 * only when that row has `given` true, no `withdrawnAt` and is not inactive. No row = never asked = **no consent**.
 * Anything that sends marketing must check `allows()` first; a statement or receipt is not marketing.
 *
 * Runs in the company scope.
 */
final class MarketingConsent
{
    public static function allows(string $customerId, ConsentChannel $channel): bool
    {
        return self::current($customerId)[$channel->value]['state'] === 'given';
    }

    /**
     * @param  array<string, string>  $branches  branch id => name
     * @return array<string, array{channel: string, label: string, state: 'given'|'withdrawn'|'none', at: string|null, source: string|null, shop: string|null}>
     */
    public static function current(string $customerId, array $branches = []): array
    {
        $latest = [];

        foreach (Consent::query()->where('customer_id', $customerId)->orderBy('at')->orderBy('id')->get() as $row) {
            if ($row->channel !== null) {
                $latest[$row->channel->value] = $row;
            }
        }

        $states = [];

        foreach (ConsentChannel::cases() as $channel) {
            $row = $latest[$channel->value] ?? null;
            $state = match (true) {
                $row === null => 'none',
                self::isGiven($row) => 'given',
                default => 'withdrawn',
            };

            $states[$channel->value] = [
                'channel' => $channel->value,
                'label' => CustomerFormat::channelLabel($channel),
                'state' => $state,
                'at' => $row === null ? null : ($state === 'withdrawn' && $row->withdrawn_at !== null ? $row->withdrawn_at : $row->at)->toIso8601ZuluString(),
                'source' => $row === null ? null : CustomerFormat::sourceLabel($row->source),
                'shop' => $row === null ? null : ($branches[(string) $row->branch_id] ?? null),
            ];
        }

        return $states;
    }

    /**
     * Every consent event, newest first: a row is "given" or "refused" at `at`; a later withdrawal is its own event.
     *
     * @param  array<string, string>  $branches
     * @return list<array{id: string, channel: string, event: 'given'|'refused'|'withdrawn', at: string, source: string, shop: string|null}>
     */
    public static function history(string $customerId, array $branches): array
    {
        $events = [];

        foreach (Consent::query()->where('customer_id', $customerId)->get() as $row) {
            if ($row->channel === null) {
                continue;
            }

            $base = ['channel' => CustomerFormat::channelLabel($row->channel), 'source' => CustomerFormat::sourceLabel($row->source), 'shop' => $branches[(string) $row->branch_id] ?? null];
            $events[] = ['id' => $row->id, 'event' => $row->given ? 'given' : 'refused', 'at' => $row->at->toIso8601ZuluString(), ...$base];

            if ($row->withdrawn_at !== null) {
                $events[] = ['id' => $row->id.'-w', 'event' => 'withdrawn', 'at' => $row->withdrawn_at->toIso8601ZuluString(), ...$base];
            }
        }

        usort($events, fn (array $a, array $b) => [$b['at'], $b['id']] <=> [$a['at'], $a['id']]);

        return $events;
    }

    /**
     * Limits a customers query to those whose latest row on the channel gives consent (the list's consent filter).
     *
     * @param  Builder<*>  $customers
     */
    public static function filter(Builder $customers, ConsentChannel $channel): void
    {
        $customers->whereExists(fn (QueryBuilder $q) => self::given($q->from('consents as c')
            ->whereColumn('c.company_id', 'customers.company_id')
            ->whereColumn('c.customer_id', 'customers.id')
            ->where('c.channel', $channel->value)
            ->whereNull('c.deleted_at')
            ->whereNotExists(fn (QueryBuilder $n) => $n->from('consents as n')
                ->whereColumn('n.company_id', 'c.company_id')
                ->whereColumn('n.customer_id', 'c.customer_id')
                ->whereColumn('n.channel', 'c.channel')
                ->whereNull('n.deleted_at')
                ->where(fn (QueryBuilder $w) => $w->whereColumn('n.at', '>', 'c.at')
                    ->orWhere(fn (QueryBuilder $t) => $t->whereColumn('n.at', 'c.at')->whereColumn('n.id', '>', 'c.id'))))));
    }

    private static function given(BuilderContract $query): BuilderContract
    {
        return $query->where('c.given', true)->whereNull('c.withdrawn_at')
            ->where(fn (QueryBuilder $w) => $w->where('c.is_active', true)->orWhereNull('c.is_active'));
    }

    private static function isGiven(Consent $row): bool
    {
        return $row->given && $row->withdrawn_at === null && $row->getAttribute('is_active') !== false;
    }
}
