<?php

namespace Tests\Feature\Purchasing;

use App\Domain\Ai\Testing\FakeAiClient;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\TillData\TillFixtures;

/**
 * Module 6.5 test data: a plan with (or without) `assist_invoice_scan`, the purchasing catalogue with the bread's
 * barcode, a scripted `record_invoice` reply and small files to upload. No test touches the network.
 */
final class InvoiceImportFixtures
{
    public static function plan(Company $company, bool $withScan = true): void
    {
        $plan = Plan::factory()->features($withScan ? [Feature::AssistInvoiceScan, Feature::Purchasing] : [Feature::Purchasing])
            ->create(['code' => 'scan-'.str()->lower(str()->random(6))]);
        $company->forceFill(['plan_id' => $plan->id])->save();
    }

    /** The purchasing catalogue (bread 0% with barcode 0400001042175, cola 20% SKU COLA-500, both cost £0.98). */
    public static function catalogue(Company $company): void
    {
        Pull::portalCreate($company, 'Department', TillFixtures::sample('entities/Department.json'));
        Pull::portalCreate($company, 'Category', TillFixtures::sample('entities/Category.json'));
        PurchasingFixtures::catalogue($company);
        Pull::portalCreate($company, 'ProductBarcode', TillFixtures::sample('entities/ProductBarcode.json'));
    }

    public static function fakeAi(): FakeAiClient
    {
        config(['ai.enabled' => true, 'ai.anthropic.api_key' => '']);

        return FakeAiClient::install();
    }

    /**
     * What the model "reads": Aire Valley invoice INV-5001, 2 × 12 bread at £12.00 (0%), 2 × 24 cola at £18.72 (20%) and
     * one crisps line no product matches. Lines add up: £66.44 net + £8.49 VAT = £74.93.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function extraction(array $overrides = []): array
    {
        return [
            'documentType' => 'invoice', 'supplierName' => 'Aire Valley Cash & Carry Ltd', 'supplierVatNumber' => null,
            'invoiceNumber' => 'INV-5001', 'invoiceDate' => '2026-09-23', 'orderReference' => null,
            'netTotal' => 66.44, 'vatTotal' => 8.49, 'grossTotal' => 74.93,
            'lines' => [
                ['description' => 'Warburtons Toastie 800g', 'barcode' => '0400001042175', 'supplierCode' => null, 'quantity' => 2, 'packSize' => 12, 'unitPrice' => 12.00, 'vatRate' => 0, 'lineTotal' => 24.00],
                ['description' => 'Coca-Cola 500ml x24', 'barcode' => null, 'supplierCode' => 'COLA-500', 'quantity' => 2, 'packSize' => 24, 'unitPrice' => 18.72, 'vatRate' => 20, 'lineTotal' => 37.44],
                ['description' => 'Mystery Crisps 32g', 'barcode' => null, 'supplierCode' => 'MC-32', 'quantity' => 1, 'packSize' => 1, 'unitPrice' => 5.00, 'vatRate' => 20, 'lineTotal' => 5.00],
            ],
            'readingNotes' => [],
            ...$overrides,
        ];
    }

    public static function pdf(string $name = 'invoice.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    /** A real file on disk, so its type is read from the content (fake files report the name's type). */
    public static function real(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'inv');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public static function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('invoice.jpg', 800, 1200);
    }
}
