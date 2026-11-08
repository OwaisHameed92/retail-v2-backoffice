<?php

namespace App\Domain\Workshop\Data;

use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\Customer;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Queries\ConnectorReference;
use App\Domain\Workshop\Support\ConnectorException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * BikeBook's `SalesOrderPostModel` / `SalesOrderPutModel`, checked and normalised (WORKSHOP-BIKEBOOK.md §3.6, §5.1).
 * Read inside the connection's company. `store` must be the connected shop; at most 200 lines (400 otherwise).
 * `customer`: our customer id (BikeBook got it from `Customer/list` or a POST), or an object with id / name / phone /
 * e-mail; a customer we do not know is kept as BikeBook's (`externalCustomerId`) with the details sent.
 * Optional extras BikeBook may send (§10 Q7): job number / id, invoice id, bike (asset), warranty / leasing /
 * motability flags, discount, due date, notes.
 */
final readonly class SalesOrderInput
{
    /**
     * @param  list<SalesOrderLineInput>  $lines
     * @param  array<string, mixed>  $header  stored columns
     */
    private function __construct(public string $orderNumber, public array $header, public array $lines) {}

    /**
     * @param  array<mixed>  $body
     */
    public static function from(array $body, WorkshopConnection $connection, ConnectorReference $reference): self
    {
        $number = trim((string) ($body['orderNumber'] ?? ''));

        if ($number === '' || mb_strlen($number) > 120) {
            throw ConnectorException::invalid('orderNumber is required (at most 120 characters).');
        }

        $store = trim((string) ($body['store'] ?? $body['storeId'] ?? ''));

        if ($store !== '' && $store !== $connection->branch_id) {
            throw ConnectorException::invalid("store {$store} is not the connected shop. Use {$connection->branch_id}.");
        }

        $lines = $body['lines'] ?? null;

        if (! is_array($lines) || ! array_is_list($lines) || $lines === []) {
            throw ConnectorException::invalid('lines must be a list with at least one line.');
        }

        $max = (int) config('workshop.max_order_lines', 200);

        if (count($lines) > $max) {
            throw ConnectorException::invalid("An order can have at most {$max} lines.");
        }

        return self::build($number, $body, array_map(
            fn (mixed $line, int $i) => SalesOrderLineInput::from(is_array($line) ? $line : [], $i + 1, $reference),
            $lines,
            array_keys($lines),
        ));
    }

    /**
     * The same header with other lines (a line added, changed or removed).
     *
     * @param  list<SalesOrderLineInput>  $lines
     */
    public function withLines(array $lines): self
    {
        $max = (int) config('workshop.max_order_lines', 200);

        if (count($lines) > $max) {
            throw ConnectorException::invalid("An order can have at most {$max} lines.");
        }

        return new self($this->orderNumber, [...$this->header, 'goods_total' => self::totalOf($lines, $this->header['discount_type'], $this->header['discount_value'])], $lines);
    }

    /**
     * @param  array<mixed>  $body
     * @param  list<SalesOrderLineInput>  $lines
     */
    private static function build(string $number, array $body, array $lines): self
    {
        $customer = $body['customer'] ?? $body['customerId'] ?? null;
        $details = is_array($customer) ? $customer : ['id' => is_scalar($customer) ? (string) $customer : ''];
        $id = trim((string) ($details['id'] ?? ''));
        $ours = $id === '' ? null : Customer::query()->whereKey($id)->whereNull('anonymised_at')->first();
        $name = trim((string) ($details['name'] ?? trim(($details['firstName'] ?? '').' '.($details['lastName'] ?? ''))));
        $job = mb_substr(trim((string) ($body['jobNumber'] ?? $body['externalJobNumber'] ?? self::jobFrom($number))), 0, 60);
        $asset = is_array($body['asset'] ?? null) ? $body['asset'] : [];
        [$discountType, $discountValue] = self::discount($body['discount'] ?? null);
        $flags = ['warranty' => (bool) ($body['isWarranty'] ?? false), 'leasing' => (bool) ($body['isLeasing'] ?? false), 'motability' => (bool) ($body['isMotability'] ?? false)];
        $billing = strtolower((string) ($body['billingType'] ?? ''));

        $header = [
            'external_job_number' => $job,
            'external_job_id' => mb_substr(trim((string) ($body['jobId'] ?? '')), 0, 100),
            'external_invoice_id' => mb_substr(trim((string) ($body['invoiceId'] ?? '')), 0, 100),
            'customer_id' => $ours?->id,
            'external_customer_id' => $ours === null ? mb_substr($id, 0, 100) : '',
            'customer_name' => mb_substr($ours !== null ? $ours->name : $name, 0, 200),
            'customer_phone' => mb_substr($ours !== null ? $ours->phone : trim((string) ($details['phone'] ?? '')), 0, 60),
            'customer_email' => mb_substr($ours !== null ? $ours->email : mb_strtolower(trim((string) ($details['emailAddress'] ?? $details['email'] ?? ''))), 0, 255),
            'asset_name' => mb_substr(trim((string) ($asset['name'] ?? $body['bikeName'] ?? '')), 0, 200),
            'asset_serial' => mb_substr(trim((string) ($asset['serial'] ?? $body['bikeSerial'] ?? '')), 0, 100),
            'external_asset_id' => mb_substr(trim((string) ($asset['id'] ?? $asset['externalAssetId'] ?? '')), 0, 100),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'goods_total' => self::totalOf($lines, $discountType, $discountValue),
            'billing_type' => in_array($billing, ['warranty', 'leasing', 'motability'], true) ? $billing : (array_search(true, $flags, true) ?: 'customer'),
            'due_at' => self::date($body['dueAt'] ?? $body['dueDate'] ?? null),
            'notes' => mb_substr(trim((string) ($body['notes'] ?? '')), 0, 2000),
        ];

        return new self($number, $header, $lines);
    }

    /** "INV#1234 [abcd]" → "1234": the first run of digits, else empty. */
    private static function jobFrom(string $number): string
    {
        return preg_match('/\d+/', $number, $m) === 1 ? $m[0] : '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function discount(mixed $discount): array
    {
        if (! is_array($discount) || ! is_numeric($discount['value'] ?? null) || Money::compare($discount['value'], '0') <= 0) {
            return ['none', '0.00'];
        }

        $type = strtolower((string) ($discount['type'] ?? ''));

        if (! in_array($type, ['percentage', 'amount'], true)) {
            throw ConnectorException::invalid('discount.type must be percentage or amount.');
        }

        if ($type === 'percentage' && Money::compare($discount['value'], '100') > 0) {
            throw ConnectorException::invalid('A percentage discount cannot be more than 100.');
        }

        return [$type, Money::normalise($discount['value'])];
    }

    /**
     * Sum of the line totals less the order discount.
     *
     * @param  list<SalesOrderLineInput>  $lines
     */
    public static function totalOf(array $lines, string $type, string $value): string
    {
        $sum = Money::sum(array_map(fn (SalesOrderLineInput $line) => $line->lineTotal, $lines));
        $off = match ($type) {
            'percentage' => Money::round(bcdiv(bcmul($sum, $value, 12), '100', 12)),
            'amount' => Money::compare($value, $sum) > 0 ? $sum : $value,
            default => '0.00',
        };

        return Money::sub($sum, $off);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            throw ConnectorException::invalid('dueAt must be a date (yyyy-MM-dd or ISO 8601).');
        }
    }

    /** Fingerprint of everything BikeBook sent, so the same body again changes nothing. */
    public function hash(): string
    {
        $header = $this->header;
        $header['due_at'] = $header['due_at']?->format('Y-m-d\TH:i:s\Z');

        return hash('sha256', (string) json_encode([$this->orderNumber, $header, array_map(fn (SalesOrderLineInput $l) => $l->columns(), $this->lines)]));
    }
}
