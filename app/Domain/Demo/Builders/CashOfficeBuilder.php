<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoJournal;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\DemoStaff;
use App\Domain\Reporting\Demo\DemoShop;
use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;

/**
 * The back office of the cash: each till's card settlement the morning after (fees taken, now and then a mismatch
 * from an offline payment), the safe counted every evening, and the cash banked every few days (banked, the latest
 * bag in transit with the carrier, today's still being prepared), each with its journal.
 *
 * @phpstan-import-type TillDay from CashBuilder
 */
final class CashOfficeBuilder
{
    /** Days of takings in one banking bag. */
    public const BANKING_EVERY = 3;

    /**
     * @param  list<TillDay>  $tillDays
     */
    public function handle(DemoBusiness $b, DemoPush $push, DemoJournal $journal, array $tillDays): void
    {
        $byShop = [];

        foreach ($tillDays as $d) {
            if (! $d['closed']) {
                continue;
            }

            $this->settlement($b, $push, $journal, $d);
            $byShop[$d['shop']->branchId][$d['day']] = ($byShop[$d['shop']->branchId][$d['day']] ?? 0) + $d['banked'];
        }

        foreach ($b->shops as ['shop' => $shop]) {
            $days = $byShop[$shop->branchId] ?? [];
            ksort($days);
            $this->banking($b, $push, $journal, $shop, $days);
            $this->safeCounts($b, $push, $shop, array_keys($days));
        }
    }

    /**
     * @param  TillDay  $d
     */
    private function settlement(DemoBusiness $b, DemoPush $push, DemoJournal $journal, array $d): void
    {
        $shop = $d['shop'];
        $rng = $b->rng("settle|{$d['register']}|{$d['day']}");
        $at = CarbonImmutable::parse($d['day'].' 02:15:00', TradingDay::timezone())->addDay()->utc();

        if ($at > $b->now || $d['card'] === 0) {
            return;
        }

        $mismatch = $rng->nextFloat() < 0.05 ? $rng->getInt(1, 15) * 100 : 0;
        $terminal = $d['card'] - $mismatch;
        $fees = (int) round($terminal * 0.0035) + 2 * $d['cardCount'];
        $id = $shop->id("settlement|{$d['register']}|{$d['day']}");
        $push->add($shop, 'CardSettlement', $id, [
            'tradingDate' => $d['day'], 'provider' => 'Dojo', 'reference' => 'DOJO-'.str_replace('-', '', $d['day']).'-'.substr($d['register'], -4),
            'batchReference' => 'B'.(abs(crc32($id)) % 900000 + 100000), 'terminalTotal' => $terminal / 100, 'posTotal' => $d['card'] / 100,
            'variance' => ($terminal - $d['card']) / 100, 'fees' => $fees / 100, 'transactionCount' => $d['cardCount'],
            'status' => $mismatch > 0 ? 'mismatched' : 'matched',
            'message' => $mismatch > 0 ? 'Terminal total lower than the till: an offline card payment declined later' : 'Settled',
            'settledAt' => DemoBusiness::iso($at), 'settledByUserId' => DemoStaff::manager($shop),
            'responseJson' => (string) json_encode(['batch' => 'closed', 'count' => $d['cardCount']]), 'registerId' => $d['register'], 'branchId' => $shop->branchId,
        ], $at);
        $journal->post($shop, "settlement|{$d['register']}|{$d['day']}", $at, 'CardSettlement', $id, "Card settlement {$d['day']}", [
            ['1200', $terminal - $fees, 0], ['7900', $fees, 0], ['1220', 0, $terminal],
        ], DemoStaff::manager($shop));
    }

    /**
     * @param  array<string, int>  $days  day => cash to bank (pence)
     */
    private function banking(DemoBusiness $b, DemoPush $push, DemoJournal $journal, DemoShop $shop, array $days): void
    {
        $bags = array_chunk($days, self::BANKING_EVERY, true);
        $manager = DemoStaff::manager($shop);

        foreach ($bags as $i => $bag) {
            $amount = array_sum($bag);
            $last = (string) array_key_last($bag);
            $prepared = CarbonImmutable::parse($last.' 21:40:00', TradingDay::timezone())->utc();
            $status = match (true) {
                $i === count($bags) - 1 => 'prepared', $i === count($bags) - 2 => 'inTransit', default => 'banked'
            };
            $banked = $prepared->addDays(1)->setTime(11, 30);
            $id = $shop->id("banking|{$last}");
            $notes = [2000 => intdiv($amount, 2000)];
            $notes[1000] = intdiv($amount - $notes[2000] * 2000, 1000);
            $notes[500] = intdiv($amount - $notes[2000] * 2000 - $notes[1000] * 1000, 500);
            $coins = $amount - $notes[2000] * 2000 - $notes[1000] * 1000 - $notes[500] * 500;
            $denominations = [['Denomination' => 20, 'Count' => $notes[2000]], ['Denomination' => 10, 'Count' => $notes[1000]], ['Denomination' => 5, 'Count' => $notes[500]], ['Denomination' => 0.01, 'Count' => $coins]];
            $variance = $status === 'banked' && $i % 7 === 3 ? -500 : 0;

            $push->add($shop, 'CashOfficeBanking', $id, [
                'reference' => sprintf('BNK-%s-%s', $shop->branchCode, str_replace('-', '', $last)), 'amount' => $amount / 100,
                'denominationsJson' => (string) json_encode($denominations), 'status' => $status, 'preparedByUserId' => $manager,
                'preparedAt' => DemoBusiness::iso($prepared), 'bankedByUserId' => $status === 'banked' ? $manager : '',
                'bankedAt' => $status === 'banked' ? DemoBusiness::iso($banked) : null, 'bankReference' => $status === 'banked' ? 'PAYIN '.(abs(crc32($id)) % 900000 + 100000) : '',
                'note' => $variance !== 0 ? 'Bank counted £5 less; a £5 note was torn and kept back' : '', 'sealNumber' => 'SEAL'.(abs(crc32($id.'s')) % 9000000 + 1000000),
                'collectionMethod' => 'carrier', 'carrierName' => 'Loomis', 'collectedSignatureName' => $status === 'prepared' ? '' : 'J. Carter',
                'collectedByUserId' => $status === 'prepared' ? '' : $manager, 'collectedAt' => $status === 'prepared' ? null : DemoBusiness::iso($prepared->addHours(13)),
                'confirmedAmount' => $status === 'banked' ? ($amount + $variance) / 100 : null, 'varianceAmount' => $status === 'banked' ? $variance / 100 : null,
                'branchId' => $shop->branchId,
            ], $status === 'banked' ? $banked : $prepared);

            if ($status === 'banked') {
                $journal->post($shop, "banking|{$last}", $banked, 'CashOfficeBanking', $id, 'Cash banked', [
                    ['1200', $amount + $variance, 0], ['8100', -$variance, 0], ['1210', 0, $amount],
                ], $manager);
            }
        }
    }

    /**
     * @param  list<string>  $days
     */
    private function safeCounts(DemoBusiness $b, DemoPush $push, DemoShop $shop, array $days): void
    {
        foreach ($days as $day) {
            $rng = $b->rng("safe|{$shop->branchId}|{$day}");
            $at = CarbonImmutable::parse($day.' 21:50:00', TradingDay::timezone())->utc();
            $expected = 50000 + $rng->getInt(0, 40) * 500;
            $variance = $rng->nextFloat() < 0.08 ? -$rng->getInt(1, 10) * 100 : 0;
            $push->add($shop, 'CashOfficeReconciliation', $shop->id("safe|{$day}"), [
                'tradingDate' => $day, 'expectedBalance' => $expected / 100, 'countedBalance' => ($expected + $variance) / 100,
                'denominationsJson' => (string) json_encode([['Denomination' => 20, 'Count' => intdiv($expected + $variance, 2000)]]),
                'reasonId' => $variance !== 0 ? $b->id('reason|recon-count') : '', 'note' => $variance !== 0 ? 'Change bags miscounted' : '',
                'countedByUserId' => DemoStaff::manager($shop), 'countedAt' => DemoBusiness::iso($at), 'variance' => $variance / 100, 'branchId' => $shop->branchId,
            ], $at);
        }
    }
}
