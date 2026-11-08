<?php

namespace App\Domain\Salon\Queries;

use App\Domain\Salon\Models\SalonCalendarFeed;
use App\Domain\TillData\Models\SalonStaffProfile;
use App\Domain\TillData\Models\TillUser;
use Illuminate\Support\Collection;

/**
 * The stylists of the given salon shops for the tenant "Salon" page (W3b), each with their calendar feed state: the
 * till's SalonStaffProfile rows (the diary's people, in the till's order), plus anyone whose feed is still on after
 * their profile left the till (so the owner can still turn it off). Tenant scope. Never a token or its hash.
 */
final class SalonStylists
{
    /**
     * @param  list<string>  $branchIds
     * @return array<string, list<array<string, mixed>>> branch id => stylists
     */
    public static function byBranch(array $branchIds): array
    {
        $profiles = SalonStaffProfile::query()->whereIn('branch_id', $branchIds)
            ->orderBy('sort_order')->orderBy('display_name')->orderBy('id')
            ->get(['id', 'branch_id', 'user_id', 'display_name', 'is_bookable', 'is_online_bookable', 'is_chair_renter', 'sort_order']);
        /** @var Collection<string, SalonCalendarFeed> $feeds */
        $feeds = SalonCalendarFeed::query()->whereIn('branch_id', $branchIds)->get()->keyBy(fn (SalonCalendarFeed $f) => $f->branch_id.'|'.$f->user_id);
        $userIds = $profiles->pluck('user_id')->merge($feeds->pluck('user_id'))->filter()->unique()->values()->all();
        $tillNames = TillUser::query()->withTrashed()->whereIn('id', $userIds)->pluck('name', 'id');

        $out = array_fill_keys($branchIds, []);
        $seen = [];

        foreach ($profiles as $profile) {
            $key = $profile->branch_id.'|'.$profile->user_id;

            if ($profile->branch_id === null || $profile->user_id === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[$profile->branch_id][] = [
                'userId' => $profile->user_id,
                'name' => self::name($profile->display_name, $tillNames[$profile->user_id] ?? null),
                'onTill' => true,
                'bookable' => (bool) $profile->is_bookable,
                'onlineBookable' => (bool) $profile->is_online_bookable,
                'chairRenter' => (bool) $profile->is_chair_renter,
                'feed' => self::feed($feeds[$key] ?? null),
            ];
        }

        foreach ($feeds as $key => $feed) {
            if (! isset($seen[$key]) && $feed->isOn()) {
                $out[$feed->branch_id][] = [
                    'userId' => $feed->user_id,
                    'name' => self::name(null, $tillNames[$feed->user_id] ?? null),
                    'onTill' => false,
                    'bookable' => false,
                    'onlineBookable' => false,
                    'chairRenter' => false,
                    'feed' => self::feed($feed),
                ];
            }
        }

        return $out;
    }

    /**
     * @return array{id: string, on: bool, last4: string|null, issuedAt: string|null, lastFetchedAt: string|null}|null
     */
    public static function feed(?SalonCalendarFeed $feed): ?array
    {
        if ($feed === null) {
            return null;
        }

        return [
            'id' => $feed->id,
            'on' => $feed->isOn(),
            'last4' => $feed->isOn() ? $feed->token_last4 : null,
            'issuedAt' => $feed->issued_at?->toIso8601ZuluString(),
            'lastFetchedAt' => $feed->isOn() ? $feed->last_fetched_at?->toIso8601ZuluString() : null,
        ];
    }

    private static function name(?string $display, ?string $tillName): string
    {
        foreach ([$display, $tillName] as $name) {
            if (trim((string) $name) !== '') {
                return trim((string) $name);
            }
        }

        return 'Unnamed stylist';
    }
}
