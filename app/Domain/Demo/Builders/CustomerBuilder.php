<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Catalogue\DemoPeople;
use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Reporting\Demo\DemoShop;
use Carbon\CarbonImmutable;

/**
 * 400 customers per business (the ids demo sales name, `customer|1` …), each signed up at one shop: loyalty card,
 * contact details, a tier by spend, marketing consent per channel (some withdrawn) and, for the first 25, a
 * newspaper account with a credit limit. One has asked to be forgotten (anonymised).
 */
final class CustomerBuilder
{
    /** Customers 1 … ACCOUNTS have a paper-bill account (LoyaltyBuilder posts their charges and payments). */
    public const ACCOUNTS = 25;

    public function handle(DemoBusiness $b, DemoPush $push): void
    {
        $rng = $b->rng('customers');
        $towns = array_map(fn (array $s) => [(string) $s['branch']->name, self::postcodeArea((string) $s['branch']->name)], $b->shops);

        for ($n = 1; $n <= DemoShop::CUSTOMERS; $n++) {
            $home = $b->shops[($n - 1) % count($b->shops)]['shop'];
            [$town, $area] = $towns[($n - 1) % count($towns)];
            $first = DemoPeople::FIRST[$rng->getInt(0, count(DemoPeople::FIRST) - 1)];
            $last = DemoPeople::LAST[$rng->getInt(0, count(DemoPeople::LAST) - 1)];
            $joined = $b->at($rng->getInt($b->history + 5, $b->history + 700), 10, $rng->getInt(0, 59));
            $anonymised = $n === 137;
            $account = $n <= self::ACCOUNTS;
            $email = strtolower(preg_replace('/[^a-z]/i', '', $first).'.'.preg_replace('/[^a-z]/i', '', $last)).$n.'@example.com';
            $id = $b->id("customer|{$n}");

            $push->add($home, 'Customer', $id, [
                'name' => $anonymised ? 'Anonymised customer' : "{$first} {$last}",
                'phone' => $anonymised ? '' : sprintf('07700 9%05d', ($n * 37) % 100000),
                'email' => $anonymised || $rng->nextFloat() < 0.15 ? '' : $email,
                'address' => $anonymised ? '' : $rng->getInt(1, 220).' '.DemoPeople::STREETS[$rng->getInt(0, count(DemoPeople::STREETS) - 1)].", {$town} {$area}",
                'dob' => $anonymised || $rng->nextFloat() < 0.4 ? null : sprintf('%04d-%02d-%02d', $rng->getInt(1948, 2006), $rng->getInt(1, 12), $rng->getInt(1, 28)),
                'cardNo' => '6339'.str_pad((string) (100000 + $n * 7919 % 900000), 8, '0', STR_PAD_LEFT),
                'balance' => 0, 'points' => 0, 'creditLimit' => $account ? [50, 75, 100, 150, 200][$n % 5] : 0,
                'tier' => match (true) {
                    $n % 11 === 0 => 'Gold', $n % 4 === 0 => 'Silver', default => 'Bronze'
                },
                'notes' => match (true) {
                    $account => 'Paper round: '.['Daily Mail and Sunday Times', 'The Sun and Sun on Sunday', 'Daily Mirror', 'The Times', 'i Newspaper'][$n % 5].', delivered by 7am.',
                    $n % 23 === 0 => 'Likes us to put a Racing Post aside on Saturdays.',
                    default => '',
                },
                'isActive' => ! $anonymised && $n % 97 !== 0,
                'anonymisedAt' => $anonymised ? DemoBusiness::iso($b->at(9, 15)) : null,
            ], $anonymised ? $b->at(9, 15) : $joined, $joined);

            if (! $anonymised) {
                $this->consents($b, $push, $home, $id, $n, $joined);
            }
        }
    }

    private function consents(DemoBusiness $b, DemoPush $push, DemoShop $home, string $customerId, int $n, CarbonImmutable $joined): void
    {
        $rng = $b->rng("consent|{$n}");

        foreach (['email', 'sms', 'post', 'whatsApp'] as $c => $channel) {
            if ($c >= 2 && $rng->nextFloat() < 0.6) {
                continue;
            }

            $given = $rng->nextFloat() < 0.7;
            $withdrawn = $given && $rng->nextFloat() < 0.08;
            $at = $withdrawn ? $b->at($rng->getInt(1, $b->history), 12) : $joined;
            $push->add($home, 'Consent', $home->id("consent|{$customerId}|{$channel}"), [
                'customerId' => $customerId, 'channel' => $channel, 'given' => $given, 'source' => $n % 3 === 0 ? 'web' : 'till',
                'at' => DemoBusiness::iso($joined), 'withdrawnAt' => $withdrawn ? DemoBusiness::iso($at) : null, 'isActive' => $given && ! $withdrawn,
            ], $at, $joined);
        }
    }

    private static function postcodeArea(string $town): string
    {
        return match (true) {
            str_contains($town, 'Leeds') => 'LS'.(abs(crc32($town)) % 17 + 1),
            str_contains($town, 'Bradford') => 'BD'.(abs(crc32($town)) % 15 + 1),
            str_contains($town, 'Wolverhampton') => 'WV'.(abs(crc32($town)) % 14 + 1),
            default => 'LS6',
        }.' '.(abs(crc32($town)) % 9 + 1).'AB';
    }
}
