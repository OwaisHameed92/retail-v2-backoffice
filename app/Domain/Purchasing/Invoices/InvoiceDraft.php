<?php

namespace App\Domain\Purchasing\Invoices;

use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The one shape of an invoice under review (module 6.5), built from the model's `record_invoice` call or from the
 * review form. Everything is cleaned here, deterministically: text is trimmed and cut to its column size, numbers are
 * parsed from "£1,234.50"-style text into fixed-scale strings (never floats), dates must be real Y-m-d dates. What the
 * model wrote is data only: nothing in it is ever run or followed.
 *
 * Line meaning: `quantity` of what the invoice sells (cases, packs or single items) × `packSize` items in each, at
 * `unitPrice` ex VAT per invoiced quantity; `lineNet` is the line total ex VAT as printed. Cost per item =
 * unitPrice ÷ packSize.
 */
final class InvoiceDraft
{
    public const MAX_LINES = 300;

    /**
     * From the model's tool input (strict schema, but checked again: never trusted).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function fromExtraction(array $input): array
    {
        $lines = [];

        foreach (array_slice(is_array($input['lines'] ?? null) ? $input['lines'] : [], 0, self::MAX_LINES) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lines[] = self::line([
                'description' => $line['description'] ?? '', 'barcode' => $line['barcode'] ?? null, 'supplierCode' => $line['supplierCode'] ?? null,
                'quantity' => $line['quantity'] ?? null, 'packSize' => $line['packSize'] ?? null, 'unitPrice' => $line['unitPrice'] ?? null,
                'vatRate' => $line['vatRate'] ?? null, 'lineNet' => $line['lineTotal'] ?? null,
            ]);
        }

        $notes = array_values(array_filter(array_map(fn ($n) => self::text($n, 200), array_slice((array) ($input['readingNotes'] ?? []), 0, 10))));

        return self::header($input) + ['lines' => array_values(array_filter($lines, fn (array $l) => $l['description'] !== '' || $l['unitPrice'] !== null)), 'readingNotes' => $notes];
    }

    /**
     * From the review form (already validated by InvoiceReviewRequest).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $previous  the draft before this save (keeps the reading notes)
     * @return array<string, mixed>
     */
    public static function fromForm(array $input, array $previous = []): array
    {
        $lines = array_map(fn (array $line) => self::line($line), array_slice(array_values((array) ($input['lines'] ?? [])), 0, self::MAX_LINES));

        return self::header($input) + ['lines' => $lines, 'readingNotes' => $previous['readingNotes'] ?? []];
    }

    /**
     * An empty draft for entering an invoice by hand.
     *
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return self::header([]) + ['lines' => [], 'readingNotes' => []];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function header(array $input): array
    {
        $type = $input['documentType'] ?? 'invoice';

        return [
            'documentType' => in_array($type, ['invoice', 'deliveryNote'], true) ? $type : 'invoice',
            'supplierName' => self::text($input['supplierName'] ?? null, 190),
            'supplierVatNumber' => self::text($input['supplierVatNumber'] ?? null, 30),
            'supplierId' => self::id($input['supplierId'] ?? null),
            'supplierPinned' => (bool) ($input['supplierPinned'] ?? false),
            'invoiceNumber' => self::text($input['invoiceNumber'] ?? null, 60),
            'invoiceDate' => self::date($input['invoiceDate'] ?? null),
            'orderReference' => self::text($input['orderReference'] ?? null, 60),
            'purchaseOrderId' => self::id($input['purchaseOrderId'] ?? null),
            'goodsReceiptId' => self::id($input['goodsReceiptId'] ?? null),
            'documentPinned' => (bool) ($input['documentPinned'] ?? false),
            'netTotal' => self::money($input['netTotal'] ?? null, 2),
            'vatTotal' => self::money($input['vatTotal'] ?? null, 2),
            'grossTotal' => self::money($input['grossTotal'] ?? null, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private static function line(array $line): array
    {
        $pack = self::money($line['packSize'] ?? null, 0);
        $quantity = self::money($line['quantity'] ?? null, 4);
        $productId = self::id($line['productId'] ?? null);
        $pinned = (bool) ($line['pinned'] ?? false);

        return [
            'description' => self::text($line['description'] ?? null, 190) ?? '',
            'barcode' => self::code($line['barcode'] ?? null),
            'supplierCode' => self::text($line['supplierCode'] ?? null, 60),
            'quantity' => $quantity !== null && Money::compare($quantity, '0') > 0 ? $quantity : '1.0000',
            'packSize' => max(1, min(10000, (int) ($pack ?? 1))),
            'unitPrice' => self::money($line['unitPrice'] ?? null, 4),
            'vatRate' => self::money($line['vatRate'] ?? null, 2),
            'lineNet' => self::money($line['lineNet'] ?? null, 2),
            'productId' => $productId,
            'pinned' => $pinned,
            'matchedBy' => $pinned ? ($productId === null ? null : 'user') : null,
            'confidence' => $pinned && $productId !== null ? 100 : 0,
            'catalogueName' => null,
        ];
    }

    public static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        // One line of plain text: control characters and markup-ish brackets out.
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', (string) $value));
        $text = (string) preg_replace('/\s{2,}/u', ' ', $text);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** A number from text such as "£1,234.50", "12.5%" or "(3.00)"; null when it is not one. */
    public static function money(mixed $value, int $scale): ?string
    {
        if (is_int($value) || is_float($value)) {
            $raw = (string) $value;
        } elseif (is_string($value)) {
            $raw = trim($value);
            $negative = str_starts_with($raw, '(') && str_ends_with($raw, ')');
            $raw = (string) preg_replace('/[£$€%,\s()]/u', '', $raw);
            $raw = $negative ? '-'.$raw : $raw;
        } else {
            return null;
        }

        if ($raw === '' || ! is_numeric($raw) || abs((float) $raw) > 99999999) {
            return null;
        }

        try {
            return Money::normalise($raw, $scale);
        } catch (Throwable) {
            return null;
        }
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $date !== null && $date->format('Y-m-d') === $value && $date->year >= 2000 && $date->year <= 2100 ? $value : null;
    }

    private static function code(mixed $value): ?string
    {
        $text = self::text($value, 40);
        $digits = $text === null ? '' : (string) preg_replace('/[\s-]/', '', $text);

        return $digits === '' ? null : mb_substr($digits, 0, 40);
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9A-Za-z]{1,64}$/', $value) === 1 ? $value : null;
    }
}
