<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Reporting\Demo\DemoShop;
use Carbon\CarbonImmutable;

/**
 * The news counter of each shop: a title per newspaper and magazine, linked to its zero-rated product and barcode,
 * and the morning deliveries from the news wholesaler for the last two weeks (papers every day, Sunday titles on
 * Sundays, magazines on Thursdays) with what sold and what went back; older ones settled, the latest just received.
 */
final class NewsBuilder
{
    public const DAYS = 14;

    public function handle(DemoBusiness $b, DemoPush $push): void
    {
        $titles = array_filter(DemoProducts::all(), fn (array $p) => $p['department'] === 'newspapers');

        foreach ($b->shops as ['shop' => $shop]) {
            foreach ($titles as $key => $p) {
                $push->add($shop, 'NewsTitle', $shop->id("news-title|{$key}"), [
                    'name' => $p['name'], 'publisher' => self::publisher($p['name']), 'frequency' => $p['category'] === 'papers' ? 'daily' : 'weekly',
                    'supplierId' => $b->id('supplier|news'), 'coverPrice' => $p['price'] / 100, 'linkedProductId' => $b->id("product|{$key}"),
                    'linkedBarcode' => $p['barcode'], 'isActive' => true, 'branchId' => $shop->branchId,
                ], $b->at($b->history + 20, 6));
            }

            for ($d = min(self::DAYS, $b->history); $d >= 0; $d--) {
                $this->delivery($b, $push, $shop, $titles, $d);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $titles
     */
    private function delivery(DemoBusiness $b, DemoPush $push, DemoShop $shop, array $titles, int $daysAgo): void
    {
        $at = $b->at($daysAgo, 5, 40);

        if ($at > $b->now) {
            return;
        }

        $weekday = CarbonImmutable::parse($b->date($daysAgo), 'UTC')->dayOfWeekIso;
        $rng = $b->rng("news|{$shop->branchId}|{$daysAgo}|".$b->date($daysAgo));
        $deliveryId = $shop->id('news-delivery|'.$b->date($daysAgo));
        $status = match (true) {
            $daysAgo >= 7 => 'settled', $daysAgo >= 1 => 'partReturned', default => 'received'
        };
        $position = 0;

        foreach ($titles as $key => $p) {
            $due = match ($p['category']) {
                'papers' => $weekday !== 7, 'sundays' => $weekday === 7, default => $weekday === 4
            };

            if (! $due) {
                continue;
            }

            $in = $p['category'] === 'magazines' ? $rng->getInt(2, 6) : $rng->getInt(4, 30);
            $returned = $status === 'received' ? 0 : $rng->getInt(0, (int) ceil($in * 0.25));
            $sold = $in - $returned;
            $push->add($shop, 'NewsDeliveryLine', $shop->id('news-delivery|'.$b->date($daysAgo)."|{$key}"), [
                'deliveryId' => $deliveryId, 'titleId' => $shop->id("news-title|{$key}"), 'titleName' => $p['name'], 'qtyIn' => $in,
                'unitCost' => $p['cost'] / 100, 'qtyReturned' => $returned, 'qtySold' => $status === 'received' ? 0 : $sold,
                'lineCost' => $in * $p['cost'] / 100, 'returnValue' => $returned * $p['cost'] / 100,
            ], $at->addMinutes(++$position));
        }

        $push->add($shop, 'NewsDelivery', $deliveryId, [
            'supplierId' => $b->id('supplier|news'), 'supplierName' => 'Smiths News', 'deliveryDate' => $b->date($daysAgo), 'status' => $status,
            'creditPostedAt' => $status === 'settled' ? DemoBusiness::iso($at->addDays(6)) : null,
            'notes' => $daysAgo === 4 ? 'Daily Mail short by 5, claimed on the portal' : '', 'branchId' => $shop->branchId,
        ], $status === 'settled' ? $at->addDays(6) : $at);
    }

    private static function publisher(string $name): string
    {
        return match (true) {
            str_contains($name, 'Sun') && ! str_contains($name, 'Sunday Times') && ! str_contains($name, 'Sunday Mirror') && ! str_contains($name, 'Sunday Express') && ! str_contains($name, 'Sunday People') => 'News UK',
            str_contains($name, 'Times') => 'News UK',
            str_contains($name, 'Mail') && ! str_contains($name, 'Birmingham') => 'DMG Media',
            str_contains($name, 'i Newspaper') => 'DMG Media',
            str_contains($name, 'Mirror') || str_contains($name, 'Express') || str_contains($name, 'Star') || str_contains($name, 'People') || str_contains($name, 'Birmingham') => 'Reach plc',
            str_contains($name, 'Guardian') || str_contains($name, 'Observer') => 'Guardian Media Group',
            str_contains($name, 'Telegraph') => 'Telegraph Media Group',
            default => 'Various',
        };
    }
}
