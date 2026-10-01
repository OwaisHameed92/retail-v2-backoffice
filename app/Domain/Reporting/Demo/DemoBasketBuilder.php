<?php

namespace App\Domain\Reporting\Demo;

use App\Domain\Demo\Catalogue\DemoProducts;
use Carbon\CarbonImmutable;

/**
 * Builds one demo basket as the till writes it (`Sale`, `SaleLine`, `SalePayment`, `SaleVat` payloads, field for
 * field like `samples/push-request.json`): completed sales, refunds (their own sale, every amount negative, pointing
 * at the original) and voided baskets (never completed, no payments). Money is worked out in whole pence.
 */
final class DemoBasketBuilder
{
    /**
     * @param  array{id: string, code: string}  $register
     * @param  list<array{0: string, 1: int}>  $items  product key, qty
     * @param  array{tender: string, cashback: int, tendered: int}  $pay  tender "cash"|"card"; cashback / tendered in pence
     * @return array{rows: list<array{0: string, 1: array<string, mixed>}>, lines: list<array<string, mixed>>, total: int}
     */
    public function sale(DemoShop $shop, array $register, string $userId, CarbonImmutable $at, int $number, array $items, array $pay, bool $voided = false, ?string $customerId = null): array
    {
        $saleId = DemoIds::at($at, "{$shop->seed}|sale|{$register['id']}|{$number}");
        $lines = [];

        foreach ($items as $position => [$key, $qty]) {
            $lines[] = $this->line($shop, $saleId, $at, $position + 1, $key, $qty);
        }

        $total = array_sum(array_map(fn (array $l) => (int) round($l['goodsTotal'] * 100), $lines));
        $sale = $this->salePayload($shop, $register, $userId, $saleId, $at, $number, $lines, $voided ? 'voided' : 'completed', 'sale', null);
        $sale['customerId'] = $customerId;
        $rows = [['Sale', $sale], ...array_map(fn (array $l) => ['SaleLine', $l], $lines)];

        if (! $voided) {
            $rows[] = ['SalePayment', $this->payment($shop, $saleId, $at, $pay, $total)];
            $rows = [...$rows, ...$this->vats($shop, $saleId, $at, $lines)];
        }

        return ['rows' => $rows, 'lines' => $lines, 'total' => $total];
    }

    /**
     * A refund of one line of an earlier sale, paid back in the same tender.
     *
     * @param  array{id: string, code: string}  $register
     * @param  array<string, mixed>  $line  the original line payload
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public function refund(DemoShop $shop, array $register, string $userId, CarbonImmutable $at, int $number, array $line, string $tender): array
    {
        $saleId = DemoIds::at($at, "{$shop->seed}|refund|{$register['id']}|{$number}");
        $back = $line;

        foreach (['qty', 'baseQty', 'lineDiscount', 'promotionDiscount', 'vatAmount', 'costAtSale', 'goodsTotal', 'lineTotal'] as $field) {
            $back[$field] = -$line[$field];
        }

        $back = array_merge($back, $this->stamp(DemoIds::at($at, "{$saleId}|line"), $shop, $at), [
            'saleId' => $saleId, 'originalLineId' => $line['id'], 'position' => 1, 'restockFlag' => true,
        ]);
        $total = (int) round($back['goodsTotal'] * 100);
        $sale = $this->salePayload($shop, $register, $userId, $saleId, $at, $number, [$back], 'completed', 'refund', (string) $line['saleId']);

        return [
            ['Sale', $sale],
            ['SaleLine', $back],
            ['SalePayment', $this->payment($shop, $saleId, $at, ['tender' => $tender, 'cashback' => 0, 'tendered' => $total], $total)],
            ...$this->vats($shop, $saleId, $at, [$back]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(DemoShop $shop, string $saleId, CarbonImmutable $at, int $position, string $key, int $qty): array
    {
        [$name, $barcode, $price, $cost, $vat, $ageRestricted] = $key === 'bag'
            ? [DemoCatalogue::BAG['name'], DemoCatalogue::BAG['barcode'], DemoCatalogue::BAG['price'], DemoCatalogue::BAG['cost'], DemoCatalogue::BAG['vat'], false]
            : self::product($key);
        $multibuy = DemoCatalogue::MULTIBUYS[$key] ?? null;
        $promo = $multibuy !== null && $qty >= $multibuy[0] ? intdiv($qty, $multibuy[0]) * ($multibuy[0] * $price - $multibuy[1]) : 0;
        $goods = $qty * $price - $promo;
        $percentage = DemoCatalogue::VAT[$vat];
        $vatAmount = (int) round($goods * $percentage / (100 + $percentage));

        return [
            'saleId' => $saleId, 'productId' => $shop->id("product|{$key}"), 'originalLineId' => null, 'position' => $position,
            'name' => $name, 'barcode' => $barcode, 'unitId' => null, 'unitFactor' => 1, 'qty' => $qty, 'baseQty' => $qty,
            'unitPrice' => $price / 100, 'lineDiscount' => $promo / 100, 'discountSource' => $promo > 0 ? 'promotion' : 'manual',
            'promotionId' => $promo > 0 ? $shop->id("promotion|{$key}") : null, 'promotionDiscount' => $promo / 100,
            'promotionName' => $promo > 0 ? (string) $multibuy[2] : '', 'discountReason' => '', 'couponDiscount' => 0,
            'vatRateId' => $shop->id("vat|{$vat}"), 'vatPercentage' => $percentage, 'vatAmount' => $vatAmount / 100, 'depositAmount' => 0,
            'costAtSale' => $qty * $cost / 100, 'restockFlag' => false, 'isDamaged' => false, 'reasonId' => null, 'ageVerifiedDob' => null,
            'isPmpPriced' => false, 'isAgeRestricted' => $ageRestricted, 'isWeighed' => false, 'isBagCharge' => $key === 'bag',
            'isCharityRoundUp' => false, 'goodsTotal' => $goods / 100, 'lineTotal' => $goods / 100,
            ...$this->stamp(DemoIds::at($at, "{$saleId}|line|{$position}"), $shop, $at),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: int, 4: string, 5: bool}
     */
    private static function product(string $key): array
    {
        $p = DemoProducts::get($key);

        return [$p['name'], $p['barcode'], $p['price'], $p['cost'], $p['vat'], $p['ageRule'] !== 'none'];
    }

    /**
     * @param  array{id: string, code: string}  $register
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function salePayload(DemoShop $shop, array $register, string $userId, string $saleId, CarbonImmutable $at, int $number, array $lines, string $status, string $type, ?string $originalSaleId): array
    {
        $sum = fn (string $field) => array_sum(array_map(fn (array $l) => (int) round($l[$field] * 100), $lines));
        $completed = $status === 'completed';

        return [
            'shiftId' => $shop->id("shift|{$register['id']}|{$userId}|{$at->toDateString()}"), 'userId' => $userId, 'customerId' => null,
            'number' => $number, 'receiptNumber' => sprintf('%s-%s-%06d', $shop->branchCode, $register['code'], $number % 1000000),
            'type' => $type, 'status' => $status, 'originalSaleId' => $originalSaleId, 'noReceipt' => false, 'refundPriceBasis' => '',
            'reasonId' => null, 'approvedBy' => '', 'voidReasonId' => null, 'voidedBy' => $completed ? '' : $userId,
            'subtotal' => ($sum('goodsTotal') + $sum('lineDiscount')) / 100, 'discountTotal' => $sum('lineDiscount') / 100,
            'promoTotal' => $sum('promotionDiscount') / 100, 'depositTotal' => 0, 'vatTotal' => $sum('vatAmount') / 100,
            'total' => $sum('goodsTotal') / 100, 'heldName' => '', 'completedAt' => $completed ? self::iso($at) : null, 'receiptJson' => '',
            'idempotencyKey' => DemoIds::at($at, "{$saleId}|idem"), 'isCompleted' => $completed,
            'registerId' => $register['id'], 'branchId' => $shop->branchId,
            ...$this->stamp($saleId, $shop, $at),
        ];
    }

    /**
     * @param  array{tender: string, cashback: int, tendered: int}  $pay
     * @return array<string, mixed>
     */
    private function payment(DemoShop $shop, string $saleId, CarbonImmutable $at, array $pay, int $total): array
    {
        $card = $pay['tender'] === 'card';
        $amount = $card ? $total + $pay['cashback'] : ($total < 0 ? $total : max($total, $pay['tendered']));
        $digits = (string) (1000 + abs(crc32($saleId)) % 9000);

        return [
            'saleId' => $saleId, 'paymentTypeId' => $shop->id("tender|{$pay['tender']}"), 'paymentTypeName' => $card ? 'Card' : 'Cash',
            'position' => 1, 'amount' => $amount / 100, 'cashback' => $card ? $pay['cashback'] / 100 : 0,
            'changeGiven' => $card ? 0 : ($amount - $total) / 100, 'status' => 'approved',
            'terminalTxnId' => $card ? 'DNA-'.substr($saleId, -8) : '', 'providerRef' => $card ? 'T-'.$at->format('Ymd').'-'.substr($saleId, -6) : '',
            'scheme' => $card ? (crc32($saleId) % 3 === 0 ? 'MASTERCARD' : 'VISA') : '', 'last4' => $card ? $digits : '',
            'authCode' => $card ? str_pad((string) (abs(crc32($saleId.'a')) % 1000000), 6, '0', STR_PAD_LEFT) : '',
            'idempotencyKey' => DemoIds::at($at, "{$saleId}|pay-idem"), 'currency' => 'GBP', 'foreignAmount' => 0, 'exchangeRate' => 0,
            'appliedAmount' => $total / 100, 'isOffline' => false,
            ...$this->stamp(DemoIds::at($at, "{$saleId}|pay"), $shop, $at),
        ];
    }

    /**
     * One SaleVat per VAT band of the lines.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function vats(DemoShop $shop, string $saleId, CarbonImmutable $at, array $lines): array
    {
        $bands = [];

        foreach ($lines as $line) {
            $code = array_search($line['vatPercentage'], DemoCatalogue::VAT, true);
            $bands[$code] ??= ['gross' => 0, 'vat' => 0, 'percentage' => $line['vatPercentage'], 'rate' => $line['vatRateId']];
            $bands[$code]['gross'] += (int) round($line['goodsTotal'] * 100);
            $bands[$code]['vat'] += (int) round($line['vatAmount'] * 100);
        }

        $rows = [];

        foreach ($bands as $code => $band) {
            $rows[] = ['SaleVat', [
                'saleId' => $saleId, 'vatRateId' => $band['rate'], 'percentage' => $band['percentage'], 'code' => (string) $code,
                'net' => ($band['gross'] - $band['vat']) / 100, 'vat' => $band['vat'] / 100, 'gross' => $band['gross'] / 100,
                ...$this->stamp(DemoIds::at($at, "{$saleId}|vat|{$code}"), $shop, $at),
            ]];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function stamp(string $id, DemoShop $shop, CarbonImmutable $at): array
    {
        return [
            'id' => $id, 'companyId' => $shop->companyId, 'createdAt' => self::iso($at), 'updatedAt' => self::iso($at),
            'rowVersion' => 1, 'deletedAt' => null, 'isDeleted' => false, 'domainEvents' => [],
        ];
    }

    private static function iso(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
