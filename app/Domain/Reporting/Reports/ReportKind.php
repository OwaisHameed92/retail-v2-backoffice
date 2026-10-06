<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Reports\Builders\DiscountsReport;
use App\Domain\Reporting\Reports\Builders\HourlyReport;
use App\Domain\Reporting\Reports\Builders\ProductSalesReport;
use App\Domain\Reporting\Reports\Builders\RefundsReport;
use App\Domain\Reporting\Reports\Builders\SalesSummaryReport;
use App\Domain\Reporting\Reports\Builders\ShiftsReport;
use App\Domain\Reporting\Reports\Builders\StaffSalesReport;
use App\Domain\Reporting\Reports\Builders\StockReport;
use App\Domain\Reporting\Reports\Builders\TendersReport;
use App\Domain\Reporting\Reports\Builders\VatReturnReport;
use App\Domain\Shared\Country\Country;

/**
 * The reports of the tenant portal (module 4.8, `/app/reports/{report}`). Sales figures come from the `rpt_*` tables
 * only; stock (now) and shifts / Z reports from the till's own rows.
 */
enum ReportKind: string
{
    case Sales = 'sales';
    case Products = 'products';
    case Refunds = 'refunds';
    case Discounts = 'discounts';
    case Vat = 'vat';
    case Tenders = 'tenders';
    case Staff = 'staff';
    case Hourly = 'hourly';
    case Stock = 'stock';
    case Shifts = 'shifts';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales summary',
            self::Products => 'Product and department sales',
            self::Refunds => 'Refunds and voids',
            self::Discounts => 'Discounts',
            self::Vat => Country::tax('VAT report'),
            self::Tenders => 'Payments',
            self::Staff => 'Staff sales',
            self::Hourly => 'Busy hours',
            self::Stock => 'Stock',
            self::Shifts => 'Shifts and Z reports',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sales => Country::tax('Sales, VAT and takings by day, week or month, by shop and by till.'),
            self::Products => 'Quantity, net sales, gross profit and margin per product and department.',
            self::Refunds => 'Refunds and voided baskets by day, by till user and by product.',
            self::Discounts => 'Money given away, by source: offers, coupons, staff and manual discounts.',
            self::Vat => Country::tax('Net, VAT and gross per rate and per period, ready for your VAT return.'),
            self::Tenders => 'Takings by payment type (cash, card, vouchers and more) and by period.',
            self::Staff => 'Sales, baskets, refunds and voids per till user.',
            self::Hourly => 'When you trade: a day-by-hour heatmap of sales and transactions.',
            self::Stock => 'Stock on hand, low and out-of-stock lines and stock value at cost, now.',
            self::Shifts => 'Closed shifts: expected against counted per payment type, variance and the Z reports.',
        };
    }

    /** Hub section. */
    public function section(): string
    {
        return match ($this) {
            self::Sales, self::Products, self::Refunds, self::Discounts => 'Sales',
            self::Vat, self::Tenders => 'Tax and payments',
            self::Staff, self::Hourly => 'People and trading hours',
            self::Stock, self::Shifts => 'Stock and cash',
        };
    }

    /** False for stock: it is "now", whatever the dates. */
    public function usesDates(): bool
    {
        return $this !== self::Stock;
    }

    public function compares(): bool
    {
        return ! in_array($this, [self::Hourly, self::Stock, self::Shifts], true);
    }

    /** Day / week / month grouping of its "by period" table. */
    public function groups(): bool
    {
        return in_array($this, [self::Sales, self::Refunds, self::Discounts, self::Vat, self::Tenders], true);
    }

    /**
     * Tabs inside the report (`?view=`); the first is the default.
     *
     * @return list<array{value: string, label: string}>
     */
    public function views(): array
    {
        return match ($this) {
            self::Stock => [['value' => 'all', 'label' => 'All lines'], ['value' => 'low', 'label' => 'Low stock'], ['value' => 'out', 'label' => 'Out of stock']],
            self::Shifts => [['value' => 'shifts', 'label' => 'Shifts'], ['value' => 'z', 'label' => 'Z reports']],
            default => [],
        };
    }

    /** @return class-string<ReportBuilder> */
    public function builder(): string
    {
        return match ($this) {
            self::Sales => SalesSummaryReport::class,
            self::Products => ProductSalesReport::class,
            self::Refunds => RefundsReport::class,
            self::Discounts => DiscountsReport::class,
            self::Vat => VatReturnReport::class,
            self::Tenders => TendersReport::class,
            self::Staff => StaffSalesReport::class,
            self::Hourly => HourlyReport::class,
            self::Stock => StockReport::class,
            self::Shifts => ShiftsReport::class,
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string, section: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $k) => ['value' => $k->value, 'label' => $k->label(), 'description' => $k->description(), 'section' => $k->section()], self::cases());
    }
}
