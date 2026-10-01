<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\DemoStaff;
use App\Domain\Reporting\Demo\DemoShop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Customers' ledgers (`CustomerTransaction`): a point per whole pound on every demo sale that names a customer,
 * £5 off for 500 points now and then, and the paper-bill accounts: an opening balance, a weekly charge and monthly
 * payments; some pay late and owe, a few are over their limit, one paid ahead and is in credit. Running balances
 * are worked out in time order; the portal keeps `Customer.balance` / `points` equal to the ledger itself.
 */
final class LoyaltyBuilder
{
    public function handle(DemoBusiness $b, DemoPush $push): void
    {
        $shops = [];
        $number = [];

        foreach ($b->shops as ['shop' => $shop]) {
            $shops[$shop->branchId] = $shop;
        }

        for ($n = 1; $n <= DemoShop::CUSTOMERS; $n++) {
            $number[$b->id("customer|{$n}")] = $n;
        }

        /** @var array<string, list<array{0: CarbonImmutable, 1: string, 2: int, 3: int, 4: string, 5: DemoShop, 6: string, 7: string, 8: string}>> $events */
        $events = [];
        $sales = DB::table('sales')->where('company_id', $b->companyId)->whereNotNull('customer_id')->where('status', 'completed')
            ->where('type', 'sale')->orderBy('completed_at')->get(['id', 'customer_id', 'branch_id', 'total', 'completed_at', 'user_id']);

        foreach ($sales as $s) {
            $shop = $shops[(string) $s->branch_id] ?? null;

            if ($shop === null || ! isset($number[(string) $s->customer_id])) {
                continue;
            }

            $at = CarbonImmutable::parse((string) $s->completed_at, 'UTC');
            $points = (int) floor((float) $s->total);
            $events[(string) $s->customer_id][] = [$at, 'pointsEarn', 0, $points, (string) $s->id, $shop, (string) $s->user_id, '', "earn|{$s->id}"];
        }

        foreach ($number as $customerId => $n) {
            if ($n <= CustomerBuilder::ACCOUNTS) {
                $events[$customerId] = [...$events[$customerId] ?? [], ...$this->account($b, $shops, $customerId, $n)];
            }
        }

        foreach ($events as $customerId => $list) {
            usort($list, fn (array $x, array $y) => [$x[0], $x[8]] <=> [$y[0], $y[8]]);
            [$balance, $points] = [0, 0];

            foreach ($list as [$at, $type, $amount, $earned, $saleId, $shop, $userId, $note, $key]) {
                if ($type === 'pointsEarn' && $points + $earned >= 500 && abs(crc32($key)) % 4 === 0) {
                    $points += $earned;
                    $this->post($push, $shop, $customerId, $key, $at, 'pointsEarn', 0, $earned, $saleId, $balance, $points, $userId, '');
                    $points -= 500;
                    $this->post($push, $shop, $customerId, "burn|{$saleId}", $at->addSecond(), 'pointsBurn', 0, -500, $saleId, $balance, $points, $userId, '£5.00 off with 500 points');

                    continue;
                }

                $balance += $amount;
                $points += $earned;
                $this->post($push, $shop, $customerId, $key, $at, $type, $amount, $earned, $saleId, $balance, $points, $userId, $note);
            }
        }
    }

    /**
     * Paper-bill account entries of one customer.
     *
     * @param  array<string, DemoShop>  $shops
     * @return list<array{0: CarbonImmutable, 1: string, 2: int, 3: int, 4: string, 5: DemoShop, 6: string, 7: string, 8: string}>
     */
    private function account(DemoBusiness $b, array $shops, string $customerId, int $n): array
    {
        $shop = array_values($shops)[($n - 1) % count($shops)];
        $manager = DemoStaff::manager($shop);
        $weekly = 800 + ($n * 173) % 1700;
        $out = [[$b->at($b->history, 8), 'opening', ($n % 3) * 1250, 0, '', $shop, $manager, 'Balance brought forward from the old till', 'a|opening']];
        $owing = $out[0][2];

        for ($d = $b->history - 1, $week = 1; $d >= 0; $d -= 7, $week++) {
            $out[] = [$b->at($d, 18, 30), 'charge', $weekly, 0, '', $shop, $manager, 'Newspaper delivery week '.$week, "a|charge|{$week}"];
            $owing += $weekly;

            // Most settle every four weeks; every fourth customer is behind; customer 7 pays ahead.
            if ($week % 4 === 0 && $n % 4 !== 0) {
                $paid = $n === 7 ? $owing + 2000 : $owing - $weekly; // the current week is billed next time
                $out[] = [$b->at(max(0, $d - 2), 11, 10), 'payment', -$paid, 0, '', $shop, $shop->cashiers[0], $n % 2 === 0 ? 'Paid by card' : 'Paid cash', "a|payment|{$week}"];
                $owing -= $paid;
            }
        }

        return array_values(array_filter($out, fn (array $e) => $e[0] <= $b->now));
    }

    private function post(DemoPush $push, DemoShop $shop, string $customerId, string $key, CarbonImmutable $at, string $type, int $amount, int $points, string $saleId, int $balance, int $pointsAfter, string $userId, string $note): void
    {
        $push->add($shop, 'CustomerTransaction', $shop->id("customer-txn|{$customerId}|{$key}"), [
            'customerId' => $customerId, 'type' => $type, 'amount' => $amount / 100, 'points' => $points, 'saleId' => $saleId,
            'balanceAfter' => $balance / 100, 'pointsAfter' => $pointsAfter, 'userId' => $userId, 'note' => $note, 'at' => DemoBusiness::iso($at),
            'branchId' => $shop->branchId,
        ], $at);
    }
}
