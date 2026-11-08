<?php

namespace App\Domain\Workshop\Data;

use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\Product;
use App\Domain\Workshop\Enums\WorkshopLineType;
use App\Domain\Workshop\Models\WorkshopOrderLine;
use App\Domain\Workshop\Queries\ConnectorReference;
use App\Domain\Workshop\Support\ConnectorException;
use InvalidArgumentException;

/**
 * One BikeBook order line, checked (WORKSHOP-BIKEBOOK.md §3.6): `variantId` an active product of the business (else
 * 400, and BikeBook sends it as a custom line), `quantity` > 0 (decimals allowed), `paidPrice` (unit price inc VAT)
 * ≥ 0, `taxRate` one of the business's VAT rates in force (absent: the product's rate, else the default rate).
 * `lineTotal` and `vatAmount` as the till's VatCalculator: qty × unit price, VAT = total × rate / (100 + rate), 2 dp.
 */
final readonly class SalesOrderLineInput
{
    public function __construct(
        public string $externalLineId,
        public ?string $productId,
        public string $name,
        public string $quantity,
        public string $unitPrice,
        public string $vatRateId,
        public string $vatPercentage,
        public WorkshopLineType $lineType,
        public string $externalLineType,
        public string $lineTotal,
        public string $vatAmount,
    ) {}

    /**
     * @param  array<mixed>  $line
     */
    public static function from(array $line, int $number, ConnectorReference $reference): self
    {
        $at = "Line {$number}";
        $variant = trim((string) ($line['variantId'] ?? ''));
        $product = null;

        if ($variant !== '') {
            $product = Product::query()->whereKey($variant)->where('is_active', true)->whereNull('archived_at')->where('is_variant_parent', false)->first();

            if ($product === null) {
                throw ConnectorException::invalid("{$at}: variantId {$variant} is not an active product of this shop. Send it as a custom line.");
            }
        }

        $name = trim((string) ($line['name'] ?? ''));
        $name = $name !== '' ? $name : (string) $product?->name;

        if ($name === '' || mb_strlen($name) > 200) {
            throw ConnectorException::invalid("{$at}: name is required (at most 200 characters).");
        }

        $quantity = self::number($line['quantity'] ?? null, 4, "{$at}: quantity");
        $price = self::number($line['paidPrice'] ?? $line['price'] ?? null, 2, "{$at}: paidPrice");

        if (Money::compare($quantity, '0') <= 0) {
            throw ConnectorException::invalid("{$at}: quantity must be more than 0.");
        }

        if (Money::isNegative($price)) {
            throw ConnectorException::invalid("{$at}: paidPrice cannot be below 0.");
        }

        $rateId = trim((string) ($line['taxRate'] ?? $line['taxRateId'] ?? ''));
        $default = (string) (collect($reference->taxRates())->firstWhere('isDefault', true)['id'] ?? '');
        $rateId = $rateId !== '' ? $rateId : ($product !== null ? $product->vat_rate_id : $default);
        $rate = $rateId === '' ? null : $reference->activeRate($rateId);

        if ($rate === null) {
            throw ConnectorException::invalid("{$at}: taxRate {$rateId} is not one of this shop's VAT rates.");
        }

        $total = Money::mul($quantity, $price);
        $percentage = Money::normalise($rate->percentage, 3);
        $vat = Money::round(bcdiv(bcmul($total, $percentage, 12), bcadd('100', $percentage, 12), 12));
        $externalType = mb_substr(trim((string) ($line['lineType'] ?? '')), 0, 40);

        return new self(
            externalLineId: mb_substr(trim((string) ($line['id'] ?? $line['lineId'] ?? '')), 0, 100),
            productId: $product?->id,
            name: $name,
            quantity: $quantity,
            unitPrice: $price,
            vatRateId: $rate->id,
            vatPercentage: $percentage,
            lineType: WorkshopLineType::fromBikeBook($externalType, $product !== null),
            externalLineType: $externalType,
            lineTotal: $total,
            vatAmount: $vat,
        );
    }

    public function withExternalId(string $externalLineId): self
    {
        return new self(
            $externalLineId, $this->productId, $this->name, $this->quantity, $this->unitPrice, $this->vatRateId, $this->vatPercentage,
            $this->lineType, $this->externalLineType, $this->lineTotal, $this->vatAmount,
        );
    }

    /** A stored line as input again (to rebuild an order around one changed line). */
    public static function fromStored(WorkshopOrderLine $line): self
    {
        return new self(
            $line->external_line_id, $line->product_id, $line->name, $line->quantity, $line->unit_price, $line->vat_rate_id,
            Money::normalise($line->vat_percentage, 3), $line->line_type, $line->external_line_type, $line->line_total, $line->vat_amount,
        );
    }

    /**
     * @return array<string, mixed> the stored columns
     */
    public function columns(): array
    {
        return [
            'external_line_id' => $this->externalLineId, 'product_id' => $this->productId, 'name' => $this->name,
            'quantity' => $this->quantity, 'unit_price' => $this->unitPrice, 'vat_rate_id' => $this->vatRateId,
            'vat_percentage' => $this->vatPercentage, 'line_type' => $this->lineType->value, 'external_line_type' => $this->externalLineType,
            'line_total' => $this->lineTotal, 'vat_amount' => $this->vatAmount,
        ];
    }

    private static function number(mixed $value, int $scale, string $label): string
    {
        if (is_bool($value) || $value === null || $value === '' || ! is_numeric($value)) {
            throw ConnectorException::invalid("{$label} must be a number.");
        }

        try {
            return Money::normalise($value, $scale);
        } catch (InvalidArgumentException) {
            throw ConnectorException::invalid("{$label} must be a number.");
        }
    }
}
