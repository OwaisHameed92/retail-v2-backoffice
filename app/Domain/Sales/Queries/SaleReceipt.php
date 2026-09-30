<?php

namespace App\Domain\Sales\Queries;

use App\Domain\Sales\Support\SaleNames;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Sale;
use App\Domain\TillData\Models\SaleLine;
use App\Domain\TillData\Models\SalePayment;
use App\Domain\TillData\Models\SaleVat;

/**
 * One sale as a receipt (module 4.6): the stored lines, discounts (manual, staff, promotion, customer group, coupon),
 * VAT rows, payments (loyalty points and order deposits included) with change, who, where, the refunds and exchanges
 * made against it, the sale it refunds, and the till's audit rows about it. Every figure is the till's stored value:
 * the portal never recalculates a receipt.
 */
final class SaleReceipt
{
    /** @return array<string, mixed> */
    public static function for(Sale $sale): array
    {
        $lines = SaleLine::query()->where('sale_id', $sale->id)->orderBy('position')->orderBy('id')->get();
        $payments = SalePayment::query()->where('sale_id', $sale->id)->orderBy('position')->orderBy('id')->get();
        $vats = SaleVat::query()->where('sale_id', $sale->id)->orderBy('percentage')->orderBy('code')->get();
        $linked = Sale::query()->where('original_sale_id', $sale->id)->orderBy('completed_at')->orderBy('id')->limit(50)->get();
        $original = $sale->original_sale_id ? Sale::query()->find($sale->original_sale_id) : null;
        $events = SaleEvents::for($sale);
        $pointsCustomers = $payments->map(fn (SalePayment $p) => (object) ['customer_id' => $p->provider_ref ?: null])->all();
        $names = SaleNames::for(
            [$sale, ...$linked->all(), ...$pointsCustomers],
            [$sale->approved_by, $sale->voided_by, ...array_column($events, 'userId')],
            [$sale->reason_id, $sale->void_reason_id, ...$lines->pluck('reason_id')->all()],
        );
        $types = PaymentType::query()->withTrashed()->whereKey($payments->pluck('payment_type_id')->unique()->all())->get()->keyBy('id');
        $tenancy = app(CurrentCompany::class);

        return [
            'sale' => [
                'id' => $sale->id,
                'receiptNumber' => $sale->receipt_number ?: '#'.$sale->number,
                'number' => $sale->number,
                'type' => $sale->type?->value,
                'status' => $sale->status?->value,
                'day' => $sale->getAttribute('trading_day'),
                'completedAt' => $sale->completed_at?->toIso8601ZuluString(),
                'startedAt' => $sale->created_at?->toIso8601ZuluString(),
                'voidedAt' => $sale->status?->value === 'voided' ? ($sale->updated_at ?? $sale->created_at)?->toIso8601ZuluString() : null,
                'receivedAt' => $sale->portal_received_at?->toIso8601ZuluString(),
                'noReceipt' => (bool) $sale->no_receipt,
                'shop' => $names->shop($sale->branch_id),
                'till' => $names->till($sale->register_id),
                'staff' => $names->person($sale->user_id),
                'approvedBy' => self::person($names, $sale->approved_by),
                'voidedBy' => self::person($names, $sale->voided_by),
                'reason' => $names->reason($sale->reason_id),
                'voidReason' => $names->reason($sale->void_reason_id),
                'refundPriceBasis' => $sale->refund_price_basis ?: null,
                'customer' => $names->customer($sale->customer_id) ?? ($sale->customer_id ? ['id' => $sale->customer_id, 'name' => 'Unknown customer', 'cardNo' => null] : null),
            ],
            'totals' => [
                'subtotal' => self::money($sale->subtotal),
                'discount' => self::money($sale->discount_total),
                'promo' => self::money($sale->promo_total),
                'deposit' => self::money($sale->deposit_total),
                'vat' => self::money($sale->vat_total),
                'net' => Money::sub($sale->total ?? '0', $sale->vat_total ?? '0'),
                'total' => self::money($sale->total),
                'tendered' => Money::sum($payments->pluck('amount')->map(fn ($v) => $v ?? '0')),
                'cashback' => Money::sum($payments->pluck('cashback')->map(fn ($v) => $v ?? '0')),
                'change' => Money::sum($payments->pluck('change_given')->map(fn ($v) => $v ?? '0')),
            ],
            'lines' => $lines->map(fn (SaleLine $l) => self::line($l, $names))->values()->all(),
            'vat' => $vats->map(fn (SaleVat $v) => [
                'code' => $v->code ?: null,
                'rate' => Money::normalise($v->percentage ?? '0', 4),
                'net' => self::money($v->net),
                'vat' => self::money($v->vat),
                'gross' => self::money($v->gross),
            ])->values()->all(),
            'payments' => $payments->map(fn (SalePayment $p) => self::payment($p, $types->get($p->payment_type_id), $names))->values()->all(),
            'original' => $original === null ? null : self::brief($original),
            'linked' => $linked->map(fn (Sale $s) => self::brief($s))->values()->all(),
            'events' => array_map(fn (array $e) => [...$e, 'user' => $names->person($e['userId'])], $events),
            'canViewCustomers' => $tenancy->can(Ability::CustomersView),
            'canViewProducts' => $tenancy->can(Ability::CatalogueView),
        ];
    }

    /** @return array<string, mixed> */
    private static function line(SaleLine $l, SaleNames $names): array
    {
        $special = in_array($l->product_id, ['ORDER-DEPOSIT', 'CHARITY-ROUNDUP'], true);

        return [
            'id' => $l->id,
            'position' => $l->position,
            'productId' => $special ? null : ($l->product_id ?: null),
            'name' => $l->name ?: 'Item',
            'barcode' => $l->barcode ?: null,
            'qty' => Money::normalise($l->qty ?? '0', 4),
            'unitPrice' => self::money($l->unit_price),
            'goodsTotal' => self::money($l->goods_total),
            'lineDiscount' => self::money($l->line_discount),
            // lineDiscount already includes the promotion and coupon shares (as reports read it): this is the rest.
            'ownDiscount' => Money::sub(Money::sub($l->line_discount ?? '0', $l->promotion_discount ?? '0'), $l->coupon_discount ?? '0'),
            'discountSource' => $l->discount_source?->value,
            'discountReason' => $l->discount_reason ?: null,
            'promotionName' => $l->promotion_name ?: null,
            'promotionDiscount' => self::money($l->promotion_discount),
            'couponDiscount' => self::money($l->coupon_discount),
            'deposit' => self::money($l->deposit_amount),
            'vatRate' => Money::normalise($l->vat_percentage ?? '0', 4),
            'vatAmount' => self::money($l->vat_amount),
            'lineTotal' => self::money($l->line_total),
            'reason' => $names->reason($l->reason_id),
            'isRefundLine' => $l->original_line_id !== null,
            'flags' => array_keys(array_filter([
                'orderDeposit' => $l->product_id === 'ORDER-DEPOSIT',
                'charity' => $l->is_charity_round_up || $l->product_id === 'CHARITY-ROUNDUP',
                'bagCharge' => (bool) $l->is_bag_charge,
                'weighed' => (bool) $l->is_weighed,
                'ageRestricted' => (bool) $l->is_age_restricted,
                'pmp' => (bool) $l->is_pmp_priced,
                'damaged' => (bool) $l->is_damaged,
                'restocked' => (bool) $l->restock_flag,
            ])),
        ];
    }

    /** @return array<string, mixed> */
    private static function payment(SalePayment $p, ?PaymentType $type, SaleNames $names): array
    {
        $name = trim((string) $p->payment_type_name) ?: ($type !== null ? $type->name : 'Payment');
        $lower = mb_strtolower($name);
        $kind = match (true) {
            ($type !== null && $type->is_points) || $lower === 'loyalty points' => 'points',
            $lower === 'order deposit' => 'deposit',
            $type !== null && $type->is_cash => 'cash',
            $type !== null && $type->is_card => 'card',
            $type !== null && $type->is_voucher => 'voucher',
            $type !== null && $type->is_account => 'account',
            default => 'other',
        };

        return [
            'id' => $p->id,
            'name' => $name,
            'kind' => $kind,
            'amount' => self::money($p->amount),
            'cashback' => self::money($p->cashback),
            'change' => self::money($p->change_given),
            'status' => $p->status?->value,
            'scheme' => $p->scheme ?: null,
            'last4' => $p->last4 ?: null,
            'authCode' => $p->auth_code ?: null,
            'reference' => $kind === 'points' ? null : ($p->provider_ref ?: ($p->terminal_txn_id ?: null)),
            'pointsCustomer' => $kind === 'points' ? $names->customer($p->provider_ref ?: null) : null,
            'offline' => (bool) $p->is_offline,
            'currency' => $p->currency && $p->currency !== 'GBP' && ! Money::isZero($p->foreign_amount ?? '0')
                ? ['code' => $p->currency, 'amount' => self::money($p->foreign_amount), 'rate' => (string) $p->exchange_rate] : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function brief(Sale $s): array
    {
        return [
            'id' => $s->id,
            'receiptNumber' => $s->receipt_number ?: '#'.$s->number,
            'type' => $s->type?->value,
            'status' => $s->status?->value,
            'total' => self::money($s->total),
            'at' => ($s->completed_at ?? $s->updated_at)?->toIso8601ZuluString(),
        ];
    }

    private static function person(SaleNames $names, ?string $id): ?string
    {
        return $id === null || $id === '' ? null : ($names->person($id) ?? 'Unknown staff member');
    }

    private static function money(mixed $value): string
    {
        return Money::normalise($value ?? '0');
    }
}
