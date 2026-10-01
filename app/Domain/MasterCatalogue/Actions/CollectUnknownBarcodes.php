<?php

namespace App\Domain\MasterCatalogue\Actions;

use App\Domain\MasterCatalogue\Jobs\RecordContributionsJob;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Support\Gtin;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Product;
use Throwable;

/**
 * After a till push (PushChanges): picks the product barcodes the master catalogue does not know and queues them for
 * the admin review queue (RecordContributionsJob), to grow the catalogue from what shops really sell.
 *
 * Anonymous by construction: only the barcode and the till's product name leave this class. No price, cost,
 * business, shop, till or row id is ever passed on, so the queue cannot tell who sold what. A business that opted out
 * (`companies.share_unknown_barcodes` false, on /app/products/catalogue) sends nothing. In-store numbers (weighed and
 * internal codes) are never shared. Never fails a push: any error is reported and ignored.
 */
final class CollectUnknownBarcodes
{
    public const CHUNK = 500;

    /**
     * @param  list<mixed>  $changes  the push envelopes as received
     */
    public function handle(Company $company, array $changes): void
    {
        if ($company->share_unknown_barcodes === false) {
            return;
        }

        try {
            $found = $this->unknown($company, $changes);

            foreach (array_chunk($found, self::CHUNK) as $chunk) {
                RecordContributionsJob::dispatch($chunk);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  list<mixed>  $changes
     * @return list<array{barcode: string, name: string}>
     */
    private function unknown(Company $company, array $changes): array
    {
        $names = [];
        $barcodes = [];

        foreach ($changes as $change) {
            if (! is_array($change) || ! in_array($change['op'] ?? null, ['I', 'U'], true) || ! is_array($change['payload'] ?? null)) {
                continue;
            }

            $payload = $change['payload'];

            if (($change['entity'] ?? null) === 'Product' && is_string($payload['name'] ?? null)) {
                $names[(string) ($change['entityId'] ?? '')] = $payload['name'];
            } elseif (($change['entity'] ?? null) === 'ProductBarcode' && is_string($payload['barcode'] ?? null) && is_string($payload['productId'] ?? null)) {
                $code = Gtin::normalise($payload['barcode']);

                if ($code !== null && ! Gtin::isInStore($code)) {
                    $barcodes[$code] = $payload['productId'];
                }
            }
        }

        if ($barcodes === []) {
            return [];
        }

        $variants = array_merge(...array_map(fn ($code) => Gtin::variants((string) $code), array_keys($barcodes)));
        $known = array_flip(MasterProduct::query()->whereIn('barcode', $variants)->pluck('barcode')->all());
        $missing = array_diff_key(array_flip(array_unique(array_values($barcodes))), $names);

        if ($missing !== []) {
            $names += Product::withoutCompanyScope()->where('company_id', $company->id)->whereIn('id', array_keys($missing))->pluck('name', 'id')->all();
        }

        $found = [];

        foreach ($barcodes as $code => $productId) {
            $name = trim((string) ($names[$productId] ?? ''));
            $isKnown = array_filter(Gtin::variants((string) $code), fn (string $v) => isset($known[$v])) !== [];

            if ($name !== '' && ! $isKnown) {
                $found[] = ['barcode' => (string) $code, 'name' => mb_substr($name, 0, 255)];
            }
        }

        return $found;
    }
}
