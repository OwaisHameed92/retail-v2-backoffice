<?php

namespace App\Domain\Salon\Online;

use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * A salon shop's online record and its public addresses (W3a, README §4.1, §9): made the first time its main till
 * calls `sync/salon/*`, and found by its slug for the public pages.
 *
 * The slug is the shop's name in address form (`kings-cuts`), with four random characters added only when another
 * shop already has it: short, stable (never changed after, even when the shop is renamed, so the links and QR codes the
 * shop printed keep working) and never a sequence. Addresses: `{APP_URL}/book/{slug}` (booking page),
 * `/book/{slug}/queue` (queue join), `/book/{slug}/m/` (start of a manage link).
 */
final class SalonOnlineShops
{
    private const SUFFIX_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Runs inside the shop's company (CurrentCompany::runAs). */
    public static function forBranch(Branch $branch, ?string $shopName = null): SalonOnlineShop
    {
        $shop = SalonOnlineShop::query()->where('branch_id', $branch->id)->first();

        if ($shop !== null) {
            return $shop;
        }

        for ($try = 0; $try < 5; $try++) {
            try {
                return SalonOnlineShop::query()->create([
                    'branch_id' => $branch->id,
                    'slug' => self::freeSlug(trim((string) $shopName) !== '' ? (string) $shopName : (string) $branch->name),
                ]);
            } catch (QueryException $e) {
                // Another call made it meanwhile, or the slug was taken in between: look again.
                $shop = SalonOnlineShop::query()->where('branch_id', $branch->id)->first();

                if ($shop !== null) {
                    return $shop;
                }
            }
        }

        throw new \RuntimeException('Could not give the salon shop a public address.');
    }

    /** The shop for a public address, across businesses (the public pages run before any company is known). */
    public static function bySlug(string $slug): ?SalonOnlineShop
    {
        if (preg_match('/^[a-z0-9-]{3,60}$/', $slug) !== 1) {
            return null;
        }

        return SalonOnlineShop::withoutCompanyScope()->where('slug', $slug)->first();
    }

    /**
     * @return array{bookingUrl: string, queueJoinUrl: string, manageUrl: string}
     */
    public static function addresses(SalonOnlineShop $shop): array
    {
        $base = rtrim(url('/book/'.$shop->slug), '/');

        return ['bookingUrl' => $base, 'queueJoinUrl' => $base.'/queue', 'manageUrl' => $base.'/m/'];
    }

    private static function freeSlug(string $name): string
    {
        $base = Str::limit(Str::slug(Str::ascii($name)), 40, '');
        $base = trim($base, '-');
        $base = strlen($base) < 3 ? 'salon'.($base === '' ? '' : '-'.$base) : $base;

        if (! SalonOnlineShop::withoutCompanyScope()->where('slug', $base)->exists()) {
            return $base;
        }

        do {
            $slug = $base.'-'.self::suffix();
        } while (SalonOnlineShop::withoutCompanyScope()->where('slug', $slug)->exists());

        return $slug;
    }

    private static function suffix(): string
    {
        $out = '';

        for ($i = 0; $i < 4; $i++) {
            $out .= self::SUFFIX_ALPHABET[random_int(0, strlen(self::SUFFIX_ALPHABET) - 1)];
        }

        return $out;
    }
}
