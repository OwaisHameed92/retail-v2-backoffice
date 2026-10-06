<?php

namespace App\Domain\Privacy\Support;

use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\CsvText;
use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;
use ZipArchive;

/**
 * Packs a customer data export (CustomerDataExport::for()) into one ZIP (module 7.7): `customer-data.json` (all of
 * it, machine readable), `summary.pdf` (readable by the customer), and CSVs of the ledger, consent history, sales,
 * customer orders and e-receipts, plus a README. Built in a temporary file the caller streams and deletes: nothing is
 * kept on the server.
 */
final class CustomerExportBundle
{
    /**
     * @param  array<string, mixed>  $data
     * @return string the temporary ZIP's path
     */
    public function build(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sspos-sar-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export ZIP.');
        }

        $zip->addFromString('README.txt', $this->readme($data));
        $zip->addFromString('customer-data.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('summary.pdf', $this->pdf($data));
        $zip->addFromString('account-ledger.csv', $this->csv(
            ['Date (UTC)', 'Type', 'Shop', 'Amount', 'Points', 'Sale', 'Note'],
            array_map(fn (array $r) => [$r['at'], $r['typeLabel'], $r['shop'], $r['amount'], $r['points'], $r['saleId'], $r['note']], $data['ledger']),
        ));
        $zip->addFromString('consent-history.csv', $this->csv(
            ['Date (UTC)', 'Channel', 'Event', 'Source', 'Shop'],
            array_map(fn (array $r) => [$r['at'], $r['channel'], $r['event'], $r['source'], $r['shop']], $data['consent']['history']),
        ));
        $zip->addFromString('sales.csv', $this->csv(
            ['Receipt', 'Completed (UTC)', 'Shop', 'Type', 'Status', 'Total', 'VAT'],
            array_map(fn (array $r) => [$r['receiptNumber'], $r['completedAt'], $r['shop'], $r['type'], $r['status'], $r['total'], $r['vat']], $data['sales']),
        ));
        $zip->addFromString('customer-orders.csv', $this->csv(
            ['Reference', 'Created (UTC)', 'Shop', 'Status', 'Name', 'Phone', 'Email', 'Goods total'],
            array_map(fn (array $r) => [$r['reference'], $r['createdAt'], $r['shop'], $r['status'], $r['name'], $r['phone'], $r['email'], $r['goodsTotal']], $data['customerOrders']),
        ));
        $zip->addFromString('e-receipts.csv', $this->csv(
            ['Sale', 'Channel', 'Sent to', 'Status', 'Sent (UTC)'],
            array_map(fn (array $r) => [$r['saleId'], $r['channel'], $r['address'], $r['status'], $r['sentAt']], $data['eReceipts']),
        ));
        $zip->close();

        return $path;
    }

    /** @param  array<string, mixed>  $data */
    public function pdf(array $data): string
    {
        return Pdf::loadView('privacy.customer-export-pdf', ['d' => $data])->setPaper('a4')->setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'isFontSubsettingEnabled' => true,
        ])->output();
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<mixed>>  $rows
     */
    public function csv(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'w+');

        if ($out === false) {
            throw new RuntimeException('Could not write the CSV.');
        }

        fputcsv($out, $header, escape: '');

        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => is_int($v) || is_float($v) ? (string) $v : CsvText::safe($v ?? ''), $row), escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @param  array<string, mixed>  $data */
    private function readme(array $data): string
    {
        return implode("\n", [
            "Your personal data held by {$data['business']}",
            'Generated '.$data['generatedAt'].' (UTC).',
            '',
            'customer-data.json   everything below in one machine-readable file',
            'summary.pdf          a readable summary',
            'account-ledger.csv   every account and loyalty points entry',
            'consent-history.csv  marketing consent given, refused and withdrawn',
            'sales.csv            sales linked to your customer account',
            'customer-orders.csv  orders taken under your email or phone number',
            'e-receipts.csv       e-receipts sent for your sales',
            '',
            'Amounts are in '.app(Country::class)->currencyName().' ('.app(Country::class)->currency().'). Times are UTC.',
            '',
        ]);
    }
}
